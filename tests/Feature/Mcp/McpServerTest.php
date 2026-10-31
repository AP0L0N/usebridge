<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\McpHelpers;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/** POST /mcp: handshake, tools/list, auth (401/403), verification, disabled users, abilities and the per-token limit. */
class McpServerTest extends TestCase
{
    use McpHelpers, RefreshDatabase, TicketHelpers;

    private const TOOLS = [
        'comment_ticket', 'create_ticket', 'get_board', 'get_ticket', 'list_agents', 'list_labels', 'list_projects',
        'list_tickets', 'move_ticket', 'post_status', 'set_project_status', 'update_ticket',
    ];

    private Workspace $workspace;

    private User $tilen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
        Notification::fake();
        $this->workspace = app(CurrentWorkspace::class)->default();
        $this->tilen = $this->workspace->owner;
    }

    #[Test]
    public function initialize_and_tools_list_over_http(): void
    {
        $token = $this->scopedToken($this->tilen, $this->workspace, 'read');

        $init = $this->mcp($token, 'initialize', [
            'protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass, 'clientInfo' => ['name' => 'cursor', 'version' => '1'],
        ])->assertOk()->assertHeader('Content-Type', 'application/json');
        $init->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'Bridge')
            ->assertJsonPath('result.capabilities.tools.listChanged', false);

        $this->app['auth']->forgetGuards();
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['Authorization' => "Bearer $token"])
            ->assertStatus(202);
        $this->mcp($token, 'ping')->assertOk()->assertJsonPath('jsonrpc', '2.0');

        $tools = $this->mcp($token, 'tools/list')->assertOk()->json('result.tools');
        $names = array_column($tools, 'name');
        sort($names);
        $this->assertSame(self::TOOLS, $names);
        $byName = array_column($tools, null, 'name');
        $this->assertSame(['key'], $byName['get_ticket']['inputSchema']['required']);
        $this->assertSame(['project', 'title'], $byName['create_ticket']['inputSchema']['required']);
        $this->assertTrue($byName['list_tickets']['annotations']['readOnlyHint']);

        $this->get('/mcp')->assertStatus(405);
    }

    #[Test]
    public function unauthenticated_requests_get_401(): void
    {
        $this->mcp(null, 'tools/list')->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->mcp('1|not-a-token', 'tools/list')->assertUnauthorized();
        // Without an Accept header too (no redirect to a login page).
        $this->app['auth']->forgetGuards();
        $this->call('POST', '/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"jsonrpc":"2.0","id":1,"method":"ping"}')
            ->assertUnauthorized();
    }

    #[Test]
    public function an_unverified_users_token_gets_403(): void
    {
        $user = $this->workspaceUser();
        $token = $this->scopedToken($user, $this->workspace, 'write');
        $user->forceFill(['email_verified_at' => null])->save();

        $this->mcp($token, 'tools/list')->assertForbidden()->assertJsonPath('verified', false);
        $user->markEmailAsVerified();
        $this->mcp($token, 'tools/list')->assertOk();
    }

    #[Test]
    public function disabled_and_deletion_pending_users_tokens_get_403(): void
    {
        $user = $this->workspaceUser();
        $token = $this->scopedToken($user, $this->workspace, 'write');
        $this->mcp($token, 'tools/list')->assertOk();

        $user->forceFill(['disabled_at' => Carbon::now()])->save();
        $this->mcp($token, 'tools/list')->assertForbidden()->assertJsonPath('message', 'This account is disabled.');
        $this->mcp($token, 'tools/call', ['name' => 'list_projects', 'arguments' => new \stdClass])->assertForbidden();

        $user->forceFill(['disabled_at' => null, 'delete_requested_at' => Carbon::now()])->save();
        $this->mcp($token, 'tools/list')->assertForbidden();

        $user->forceFill(['delete_requested_at' => null])->save();
        $this->mcp($token, 'tools/list')->assertOk();
    }

    #[Test]
    public function the_limit_is_120_requests_per_minute_per_token(): void
    {
        $token = $this->scopedToken($this->tilen, $this->workspace, 'read');
        $other = $this->scopedToken($this->tilen, $this->workspace, 'read', 'other');

        for ($i = 0; $i < 120; $i++) {
            $this->mcp($token, 'ping')->assertOk();
        }
        $this->mcp($token, 'ping')->assertStatus(429);
        $this->mcp($other, 'ping')->assertOk();

        $this->travel(61)->seconds();
        $this->mcp($token, 'ping')->assertOk();
    }

    #[Test]
    public function a_read_only_token_reads_but_cannot_call_write_tools(): void
    {
        $writer = $this->scopedToken($this->tilen, $this->workspace, 'write');
        $key = $this->tool($writer, 'create_ticket', ['project' => self::BH, 'title' => 'Draft the brief', 'actor' => self::JANICE])['data']['key'];
        $read = $this->scopedToken($this->tilen, $this->workspace, 'read');
        $statuses = count($this->bearer($read, 'GET', '/api/status-updates?project_id='.self::BH)->json('data'));
        $projectStatus = $this->bearer($read, 'GET', '/api/projects/'.self::BH.'/status')->json('data.current');

        foreach (['list_projects' => [], 'get_board' => ['project' => self::BH], 'list_tickets' => [], 'get_ticket' => ['key' => $key],
            'list_agents' => [], 'list_labels' => []] as $name => $args) {
            $this->tool($read, $name, $args);
        }

        foreach ([
            'create_ticket' => [['project' => self::BH, 'title' => 'Sneaky'], 'boards:write'],
            'update_ticket' => [['key' => $key, 'title' => 'Sneaky'], 'boards:write'],
            'move_ticket' => [['key' => $key, 'status' => 'done'], 'boards:write'],
            'comment_ticket' => [['key' => $key, 'body' => 'Sneaky'], 'boards:write'],
            'post_status' => [['project' => self::BH, 'body' => 'Sneaky'], 'status:write'],
            'set_project_status' => [['project' => self::BH, 'status' => 'waiting'], 'status:write'],
        ] as $name => [$args, $ability]) {
            $message = $this->toolError($read, $name, [...$args, 'actor' => self::JANICE]);
            $this->assertSame("This token lacks the '$ability' ability.", $message);
        }

        $ticket = $this->bearer($read, 'GET', "/api/tickets/$key")->assertOk();
        $ticket->assertJsonPath('data.title', 'Draft the brief')->assertJsonPath('data.status', 'todo')->assertJsonCount(0, 'data.comments');
        $this->bearer($read, 'GET', '/api/tickets?q=Sneaky')->assertOk()->assertJsonCount(0, 'data');
        $this->bearer($read, 'GET', '/api/status-updates?project_id='.self::BH)->assertJsonCount($statuses, 'data');
        $this->bearer($read, 'GET', '/api/projects/'.self::BH.'/status')->assertJsonPath('data.current', $projectStatus);
    }

    #[Test]
    public function legacy_ceo_and_star_tokens_pass_every_ability(): void
    {
        foreach (['ceo', '*'] as $ability) {
            $token = $this->tilen->createToken("legacy-$ability", [$ability])->plainTextToken;
            $this->tool($token, 'list_projects');
            $this->tool($token, 'post_status', ['project' => self::BH, 'body' => "via $ability", 'actor' => self::CAPTAIN]);
            $this->tool($token, 'create_ticket', ['project' => self::BH, 'title' => "via $ability", 'actor' => self::CAPTAIN]);
        }
    }
}
