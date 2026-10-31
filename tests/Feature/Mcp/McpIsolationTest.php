<?php

namespace Tests\Feature\Mcp;

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\AccountService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\McpHelpers;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/**
 * MCP isolation (docs/MULTI-USER-PLAN.md §5): neither another user nor Tilen (owner, is_admin) can read or change a
 * workspace that is not their token's. Another workspace's project or ticket answers exactly like one that does not
 * exist, and its data stays unchanged.
 */
class McpIsolationTest extends TestCase
{
    use McpHelpers, RefreshDatabase, TicketHelpers;

    private User $tilen;

    private string $tilenToken;

    private User $marta;

    private string $martaToken;

    private string $martaKey;

    private string $tilenKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
        Notification::fake();
        $tilens = app(CurrentWorkspace::class)->default();
        $this->tilen = $tilens->owner;
        $this->assertTrue($this->tilen->is_admin);
        $this->tilenToken = $this->scopedToken($this->tilen, $tilens, 'write', 'tilen-laptop');
        $this->tilenKey = $this->tool($this->tilenToken, 'create_ticket', ['project' => self::BH, 'title' => 'Tilen secret ticket', 'actor' => self::JANICE])['data']['key'];

        $this->marta = app(AccountService::class)->register('Marta', 'marta@example.test', 'correct horse battery', 'Marta Labs');
        $this->marta->markEmailAsVerified();
        $workspace = Workspace::query()->where('owner_user_id', $this->marta->id)->firstOrFail();
        app(CurrentWorkspace::class)->runAs($workspace, fn () => Agent::query()->create(['id' => 'agent-marta', 'name' => 'Marta Bot', 'role_label' => 'PM']));
        $seed = $this->marta->createToken('seed', ['*'])->plainTextToken;
        $this->bearer($seed, 'POST', '/api/projects', ['id' => 'proj-marta', 'name' => 'Marta Lab', 'slug' => 'marta-lab', 'owner_agent_id' => 'agent-marta', 'actor' => 'agent-marta'])->assertCreated();
        $this->martaToken = $this->scopedToken($this->marta, $workspace, 'write', 'agent-marta');
        $this->martaKey = $this->tool($this->martaToken, 'create_ticket', ['project' => 'proj-marta', 'title' => 'Marta secret ticket', 'labels' => ['marta-label']])['data']['key'];
        $this->tool($this->martaToken, 'comment_ticket', ['key' => $this->martaKey, 'body' => 'Marta secret comment']);
        $this->tool($this->martaToken, 'post_status', ['project' => 'marta-lab', 'body' => 'Marta secret status']);
    }

    /** Every tool aimed at $project / $key of the other workspace, as the one acting with $token. */
    private function attempts(string $project, string $key, string $actor): array
    {
        return [
            ['get_board', ['project' => $project]],
            ['list_tickets', ['project' => $project]],
            ['get_ticket', ['key' => $key]],
            ['create_ticket', ['project' => $project, 'title' => 'Intruder', 'actor' => $actor]],
            ['update_ticket', ['key' => $key, 'title' => 'Intruder', 'actor' => $actor]],
            ['move_ticket', ['key' => $key, 'status' => 'done', 'actor' => $actor]],
            ['comment_ticket', ['key' => $key, 'body' => 'Intruder', 'actor' => $actor]],
            ['post_status', ['project' => $project, 'body' => 'Intruder', 'actor' => $actor]],
            ['set_project_status', ['project' => $project, 'status' => 'waiting', 'actor' => $actor]],
        ];
    }

    /** The other workspace's project/ticket gives the same error as a missing one (only the echoed ref differs). */
    private function assertNotFoundEverywhere(string $token, string $project, string $key, string $actor): void
    {
        foreach ($this->attempts($project, $key, $actor) as [$name, $args]) {
            $message = $this->toolError($token, $name, $args);
            $missing = $this->toolError($token, $name, str_replace([$project, $key], ['proj-missing', 'ZZ-999'], $args));
            $this->assertSame(isset($args['key']) ? "Unknown ticket '$key'." : "Unknown project '$project'.", $message, $name);
            $this->assertSame(str_replace([$project, $key], ['proj-missing', 'ZZ-999'], $message), $missing, $name);
        }
    }

    private function snapshot(string $token, string $project, string $key): array
    {
        return [
            $this->bearer($token, 'GET', "/api/tickets/$key")->assertOk()->json('data'),
            $this->bearer($token, 'GET', "/api/projects/$project/board")->assertOk()->json('data'),
            $this->bearer($token, 'GET', "/api/projects/$project/status")->assertOk()->json('data'),
            $this->bearer($token, 'GET', "/api/status-updates?project_id=$project")->assertOk()->json('data'),
        ];
    }

    #[Test]
    public function tilen_as_owner_and_admin_cannot_reach_another_workspace(): void
    {
        $before = $this->snapshot($this->martaToken, 'proj-marta', $this->martaKey);

        $this->assertNotFoundEverywhere($this->tilenToken, 'proj-marta', $this->martaKey, self::JANICE);
        $this->assertNotFoundEverywhere($this->tilenToken, 'marta-lab', $this->martaKey, self::CAPTAIN);
        // Another workspace's agent is not an actor here either.
        $this->assertStringContainsString("Unknown actor 'agent-marta'", $this->toolError($this->tilenToken, 'create_ticket', ['project' => self::BH, 'title' => 'x', 'actor' => 'agent-marta']));

        $this->assertSame($before, $this->snapshot($this->martaToken, 'proj-marta', $this->martaKey));
        $this->assertListsHideOtherWorkspace($this->tilenToken, ['proj-marta', 'marta-lab', 'Marta', $this->martaKey, 'marta-label', 'agent-marta']);
    }

    #[Test]
    public function another_user_cannot_reach_tilens_workspace(): void
    {
        $before = $this->snapshot($this->tilenToken, self::BH, $this->tilenKey);

        $this->assertNotFoundEverywhere($this->martaToken, self::BH, $this->tilenKey, 'agent-marta');
        $this->assertNotFoundEverywhere($this->martaToken, 'blue-harbor', $this->tilenKey, 'agent-marta');

        $this->assertSame($before, $this->snapshot($this->tilenToken, self::BH, $this->tilenKey));
        $this->assertListsHideOtherWorkspace($this->martaToken, [self::BH, self::NW, 'Harbor', 'Northwind', $this->tilenKey, 'Tilen secret', self::JANICE, 'Janice']);

        // Her own workspace works, and what she writes stays there.
        $this->assertSame(['proj-marta'], array_column($this->tool($this->martaToken, 'list_projects')['data'], 'id'));
        $this->assertSame([$this->martaKey], array_column($this->tool($this->martaToken, 'list_tickets')['data'], 'key'));
        $this->bearer($this->tilenToken, 'GET', '/api/tickets?q=Marta')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function tools_list_is_the_same_for_everyone_and_names_no_workspace(): void
    {
        $tilens = $this->mcp($this->tilenToken, 'tools/list')->assertOk();
        $martas = $this->mcp($this->martaToken, 'tools/list')->assertOk();
        $this->assertSame($tilens->json('result.tools'), $martas->json('result.tools'));
        foreach (['Marta', 'proj-marta', 'Harbor', 'Northwind', self::BH, self::JANICE, "\"{$this->martaKey}\"", "\"{$this->tilenKey}\""] as $secret) {
            $this->assertStringNotContainsString($secret, $tilens->getContent());
        }
    }

    private function assertListsHideOtherWorkspace(string $token, array $secrets): void
    {
        foreach (['list_projects', 'list_tickets', 'list_agents', 'list_labels'] as $name) {
            $text = $this->callTool($token, $name)['text'];
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $text, "$name leaks $secret");
            }
        }
    }
}
