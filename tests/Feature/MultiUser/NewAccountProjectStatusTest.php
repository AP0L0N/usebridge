<?php

namespace Tests\Feature\MultiUser;

use App\Models\Project;
use App\Models\User;
use App\Support\Workspaces\CurrentWorkspace;
use Database\Seeders\BridgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\McpHelpers;
use Tests\TestCase;

/**
 * Go-live N1/N2: a brand-new (non-legacy) account's bot sets its project's status and posts status updates through
 * REST and MCP although the project has no PM, and both show on the board (Inertia props). The legacy workspace keeps
 * the PM/Captain rule. Writes are labelled with their real source (api | mcp | ui). Synthetic data only.
 */
class NewAccountProjectStatusTest extends TestCase
{
    use McpHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Notification::fake();
        config(['turnstile.site_key' => null, 'turnstile.secret_key' => null]);
    }

    /** Sign up, verify through the signed link, first project, then a write token from the Tokens screen. */
    private function newAccount(string $name, string $email, string $project, string $prefix, string $tokenName): array
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $formToken = $this->get('/signup')->assertOk()->inertiaProps('formToken');
        $this->travel(5)->seconds();
        $this->postJson('/signup', [
            'name' => $name, 'email' => $email, 'password' => 'quiet-meadow-lantern-42',
            'form_token' => $formToken, 'website' => '', 'accept_terms' => true,
        ])->assertCreated();
        $user = User::query()->where('email', $email)->firstOrFail();

        // Creating a token needs the verified email.
        $this->postJson('/account/tokens', ['name' => $tokenName, 'scope' => 'write', 'expires' => null])->assertForbidden();
        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($link)->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->app['auth']->forgetGuards(); // next request reloads the (now verified) user from the session

        $this->postJson('/projects', ['name' => $project, 'slug' => strtolower(str_replace(' ', '-', $project)), 'ticket_prefix' => $prefix])->assertCreated();
        $res = $this->postJson('/account/tokens', ['name' => $tokenName, 'scope' => 'write', 'expires' => null])->assertCreated();
        $agentId = $res->json('data.agent_id');
        $this->assertMatchesRegularExpression('/^agent-[a-z0-9]+(-[a-z0-9]+)*$/', (string) $agentId);
        $this->assertSame($agentId, PersonalAccessToken::query()->findOrFail($res->json('data.id'))->agent_id);

        // From here on the bot talks to Bridge with the bearer token only (no browser session).
        $this->postJson('/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $projectId = Project::withoutGlobalScopes()->where('workspace_id', $user->homeWorkspace()->id)->value('id');

        return ['user' => $user, 'token' => $res->json('plain_text_token'), 'agent' => $agentId, 'project' => $projectId];
    }

    private function board(User $user, string $slug): array
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');
        $res = $this->get("/projects/$slug")->assertOk();

        return ['latestUpdate' => $res->inertiaProps('latestUpdate'), 'statusHistory' => $res->inertiaProps('statusHistory'), 'board' => $res->inertiaProps('board')];
    }

    #[Test]
    public function a_new_accounts_bot_sets_status_and_posts_updates_via_rest_and_mcp_and_both_show_on_the_board(): void
    {
        $a = $this->newAccount('Sam Rivera', 'sam@example.test', 'Garden Planner', 'GP', 'Garden bot');
        $this->assertNull(Project::withoutGlobalScopes()->findOrFail($a['project'])->owner_agent_id); // no PM

        // REST: status (no actor) + update (no agent_id).
        $this->bearer($a['token'], 'PATCH', "/api/projects/{$a['project']}/status", ['status' => 'smooth', 'note' => 'Beds are planted.'])
            ->assertOk()->assertJsonPath('data.current.status', 'smooth')->assertJsonPath('data.current.set_by', $a['agent'])
            ->assertJsonPath('data.event.source', 'api');
        $this->bearer($a['token'], 'POST', '/api/status-updates', ['project_id' => $a['project'], 'body' => 'Week 1: seedlings are up.'])
            ->assertCreated()->assertJsonPath('data.agent_id', $a['agent']);

        $b = $this->board($a['user'], 'garden-planner');
        $this->assertSame('smooth', $b['board']['project']['status']);
        $this->assertSame('Beds are planted.', $b['board']['project']['status_note']);
        $this->assertSame($a['agent'], $b['statusHistory'][0]['set_by']);
        $this->assertSame('api', $b['statusHistory'][0]['source']);
        $this->assertSame('Week 1: seedlings are up.', $b['latestUpdate']['body']);
        $this->assertSame('Garden bot', $b['latestUpdate']['agent_name']);
        $this->assertSame(1, $b['latestUpdate']['count']);

        // MCP: waiting, then archived and back (own workspace: any status), and post_status.
        $this->travel(1)->minutes();
        $r = $this->tool($a['token'], 'set_project_status', ['project' => $a['project'], 'status' => 'waiting', 'note' => 'Need the soil test.']);
        $this->assertSame('mcp', $r['data']['event']['source']);
        $this->tool($a['token'], 'set_project_status', ['project' => 'garden-planner', 'status' => 'archived']);
        $this->tool($a['token'], 'set_project_status', ['project' => 'garden-planner', 'status' => 'waiting', 'note' => 'Need the soil test.']);
        $this->travel(1)->minutes();
        $this->tool($a['token'], 'post_status', ['project' => 'garden-planner', 'body' => "Week 2: **watering** schedule set.\n\nNext: mulch."]);

        $b = $this->board($a['user'], 'garden-planner');
        $this->assertSame('waiting', $b['board']['project']['status']);
        $this->assertSame('Need the soil test.', $b['board']['project']['status_note']);
        $this->assertSame(['waiting', 'archived', 'waiting', 'smooth'], array_column($b['statusHistory'], 'to_status'));
        $this->assertSame('mcp', $b['statusHistory'][0]['source']);
        $this->assertStringStartsWith('Week 2:', $b['latestUpdate']['body']);
        $this->assertSame(2, $b['latestUpdate']['count']);
        $this->assertSame($a['agent'], $b['latestUpdate']['agent_id']);
    }

    #[Test]
    public function writes_are_labelled_with_their_real_source(): void
    {
        $a = $this->newAccount('Sam Rivera', 'sam@example.test', 'Garden Planner', 'GP', 'Garden bot');
        $rest = $this->bearer($a['token'], 'POST', "/api/projects/{$a['project']}/tickets", ['title' => 'Plant tomatoes'])->assertCreated()->json('data');
        $this->bearer($a['token'], 'POST', "/api/tickets/{$rest['key']}/comments", ['body' => 'Via REST.'])->assertCreated()->assertJsonPath('data.source', 'api');
        $mcp = $this->tool($a['token'], 'create_ticket', ['project' => $a['project'], 'title' => 'Water the beds'])['data'];
        $this->tool($a['token'], 'comment_ticket', ['key' => $mcp['key'], 'body' => 'Via MCP.']);

        $this->app['auth']->forgetGuards();
        $this->actingAs($a['user'], 'web');
        $cards = collect($this->get('/projects/garden-planner')->assertOk()->inertiaProps('board')['columns'])->flatMap(fn ($c) => $c['tickets'])->keyBy('key');
        $this->assertSame('api', $cards[$rest['key']]['latest_comment']['source']);
        $this->assertSame('mcp', $cards[$mcp['key']]['latest_comment']['source']);

        $restDetail = $this->get("/projects/garden-planner/tickets/{$rest['key']}")->assertOk()->inertiaProps('ticket');
        $this->assertSame('api', $restDetail['created_source']);
        $this->assertSame('api', $restDetail['comments'][0]['source']);
        $mcpDetail = $this->get("/projects/garden-planner/tickets/{$mcp['key']}")->assertOk()->inertiaProps('ticket');
        $this->assertSame('mcp', $mcpDetail['created_source']);
        $this->assertSame('mcp', $mcpDetail['comments'][0]['source']);

        // A web comment is ui.
        $this->postJson("/projects/garden-planner/tickets/{$rest['key']}/comments", ['body' => 'From the web.'])->assertSuccessful();
        $detail = $this->get("/projects/garden-planner/tickets/{$rest['key']}")->assertOk()->inertiaProps('ticket');
        $this->assertSame('ui', $detail['comments'][1]['source']);
    }

    #[Test]
    public function status_writes_never_cross_workspaces(): void
    {
        $a = $this->newAccount('Sam Rivera', 'sam@example.test', 'Garden Planner', 'GP', 'Garden bot');
        $b = $this->newAccount('Kim Lee', 'kim@example.test', 'Bake Shop', 'BS', 'Bake bot');

        $this->bearer($a['token'], 'PATCH', "/api/projects/{$b['project']}/status", ['status' => 'smooth'])->assertNotFound();
        $this->bearer($a['token'], 'PATCH', '/api/projects/bake-shop/status', ['status' => 'archived'])->assertNotFound();
        $this->bearer($a['token'], 'PATCH', "/api/projects/{$a['project']}/status", ['status' => 'smooth', 'actor' => $b['agent']])
            ->assertUnprocessable()->assertJsonValidationErrors('actor');
        $this->bearer($a['token'], 'POST', '/api/status-updates', ['project_id' => $b['project'], 'body' => 'x'])->assertNotFound();
        $this->bearer($a['token'], 'POST', '/api/status-updates', ['project_id' => $a['project'], 'body' => 'x', 'agent_id' => $b['agent']])
            ->assertUnprocessable()->assertJsonValidationErrors('agent_id');
        $this->assertStringContainsString('Unknown', $this->toolError($a['token'], 'set_project_status', ['project' => $b['project'], 'status' => 'smooth']));
        $this->assertStringContainsString('Unknown', $this->toolError($a['token'], 'post_status', ['project' => 'bake-shop', 'body' => 'x']));

        $this->bearer($b['token'], 'GET', "/api/projects/{$b['project']}/status")->assertOk()
            ->assertJsonPath('data.current.status', null)->assertJsonPath('data.history', []);
        $this->bearer($b['token'], 'GET', '/api/status-updates')->assertOk()->assertJsonCount(0, 'data');
        $this->assertNull($this->board($b['user'], 'bake-shop')['latestUpdate']);
    }

    #[Test]
    public function the_legacy_workspace_keeps_the_pm_and_captain_rule(): void
    {
        $this->seed(BridgeSeeder::class);
        $owner = $this->workspaceOwner();
        $ws = app(CurrentWorkspace::class)->default();
        $plain = $this->scopedToken($owner, $ws, 'write', 'Owner laptop');
        $bh = BridgeSeeder::PROJ_BLUE_HARBOR;

        // The owner's own token agent is not Blue Harbor's PM: 403 over REST and MCP, nothing written.
        $this->bearer($plain, 'PATCH', "/api/projects/$bh/status", ['status' => 'smooth'])->assertForbidden();
        $this->assertStringContainsString("Only the project's PM", $this->toolError($plain, 'set_project_status', ['project' => $bh, 'status' => 'smooth']));
        $this->bearer($plain, 'PATCH', "/api/projects/$bh/status", ['status' => 'smooth', 'actor' => BridgeSeeder::AGENT_ANATOLY])->assertForbidden();
        $this->assertNull(Project::query()->findOrFail($bh)->status_set_by);

        // The PM and Captain still can; only Captain archives.
        $this->bearer($plain, 'PATCH', "/api/projects/$bh/status", ['status' => 'archived', 'actor' => BridgeSeeder::AGENT_JANICE])->assertForbidden();
        $this->bearer($plain, 'PATCH', "/api/projects/$bh/status", ['status' => 'smooth', 'actor' => BridgeSeeder::AGENT_JANICE])->assertOk();
        $this->bearer($plain, 'PATCH', "/api/projects/$bh/status", ['status' => 'archived', 'actor' => BridgeSeeder::AGENT_WILLIAM])->assertOk();
    }

    #[Test]
    public function archive_and_unarchive_validate_the_actor_and_always_log_a_status_event(): void
    {
        $a = $this->newAccount('Sam Rivera', 'sam@example.test', 'Garden Planner', 'GP', 'Garden bot');
        $b = $this->newAccount('Kim Lee', 'kim@example.test', 'Bake Shop', 'BS', 'Bake bot');
        $events = fn () => $this->bearer($a['token'], 'GET', "/api/projects/{$a['project']}/status")->assertOk()->json('data.history');

        // Made-up or foreign actor: 422 "Unknown actor" on every archive route, nothing changes, nothing is logged.
        foreach (['agent-nope', $b['agent']] as $bad) {
            foreach (['archive', 'unarchive'] as $route) {
                $this->bearer($a['token'], 'POST', "/api/projects/{$a['project']}/$route", ['actor' => $bad])
                    ->assertUnprocessable()->assertJsonValidationErrors('actor')->assertJsonPath('errors.actor.0', fn ($m) => str_contains($m, 'Unknown actor'));
            }
            $this->bearer($a['token'], 'PATCH', "/api/projects/{$a['project']}", ['archived' => true, 'actor' => $bad])->assertUnprocessable();
        }
        $this->assertSame([], $events());
        $this->assertNull(Project::withoutGlobalScopes()->findOrFail($a['project'])->archived_at);

        // No actor: the token's own agent archives and reopens; both are status events with set_by + source.
        $this->bearer($a['token'], 'POST', "/api/projects/{$a['project']}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
        $p = Project::withoutGlobalScopes()->findOrFail($a['project']);
        $this->assertSame(['archived', $a['agent']], [$p->status, $p->status_set_by]);
        $this->assertNotNull($p->archived_at);
        $this->travel(1)->minutes();
        $row = collect($this->bearer($a['token'], 'POST', "/api/projects/{$a['project']}/unarchive")->assertOk()->json('data'));
        $this->assertSame('pm', $row['status_source']);
        $h = $events();
        $this->assertSame(['smooth', 'archived'], array_column($h, 'to_status'));
        $this->assertSame([$a['agent'], $a['agent']], array_column($h, 'set_by'));
        $this->assertSame(['api', 'api'], array_column($h, 'source'));
        $this->assertNull(Project::withoutGlobalScopes()->findOrFail($a['project'])->archived_at);

        // Its own agent named explicitly works too; B's project stays 404.
        $this->bearer($a['token'], 'POST', "/api/projects/{$a['project']}/archive", ['actor' => $a['agent']])->assertOk();
        $this->bearer($a['token'], 'POST', "/api/projects/{$b['project']}/archive")->assertNotFound();
        $this->assertNull(Project::withoutGlobalScopes()->findOrFail($b['project'])->archived_at);
    }

    #[Test]
    public function the_legacy_workspace_keeps_the_captain_rule_for_archive_routes(): void
    {
        $this->seed(BridgeSeeder::class);
        $plain = $this->scopedToken($this->workspaceOwner(), app(CurrentWorkspace::class)->default(), 'write', 'Owner laptop');
        $bh = BridgeSeeder::PROJ_BLUE_HARBOR;

        $this->bearer($plain, 'POST', "/api/projects/$bh/archive")->assertForbidden();
        $this->bearer($plain, 'POST', "/api/projects/$bh/archive", ['actor' => BridgeSeeder::AGENT_JANICE])->assertForbidden(); // PM
        $this->bearer($plain, 'POST', "/api/projects/$bh/archive", ['actor' => 'agent-nope'])->assertUnprocessable();
        $this->assertNull(Project::query()->findOrFail($bh)->archived_at);
        $this->bearer($plain, 'POST', "/api/projects/$bh/archive", ['actor' => BridgeSeeder::AGENT_WILLIAM])->assertOk();
        $this->bearer($plain, 'POST', "/api/projects/$bh/unarchive", ['actor' => BridgeSeeder::AGENT_JANICE])->assertForbidden();
        $this->bearer($plain, 'POST', "/api/projects/$bh/unarchive", ['actor' => BridgeSeeder::AGENT_WILLIAM])->assertOk();
    }

    #[Test]
    public function status_updates_and_web_writes_carry_their_source(): void
    {
        $a = $this->newAccount('Sam Rivera', 'sam@example.test', 'Garden Planner', 'GP', 'Garden bot');
        $this->bearer($a['token'], 'POST', '/api/status-updates', ['project_id' => $a['project'], 'body' => 'Via REST.'])
            ->assertCreated()->assertJsonPath('data.source', 'api');
        $this->assertSame('api', $this->board($a['user'], 'garden-planner')['latestUpdate']['source']);

        $this->travel(1)->minutes();
        $this->tool($a['token'], 'post_status', ['project' => $a['project'], 'body' => 'Via MCP.']);
        $this->assertSame('mcp', $this->board($a['user'], 'garden-planner')['latestUpdate']['source']);

        $this->travel(1)->minutes();
        $this->post('/projects/garden-planner/status-updates', ['body' => 'Via the web.', 'agent_id' => $a['agent']])->assertRedirect();
        $b = $this->board($a['user'], 'garden-planner');
        $this->assertSame(['Via the web.', 'ui', 3], [$b['latestUpdate']['body'], $b['latestUpdate']['source'], $b['latestUpdate']['count']]);
        $this->bearer($a['token'], 'GET', '/api/status-updates')->assertOk()->assertJsonPath('data.0.source', 'ui');

        // A web status change is a ui event (the status block reads "via Bridge").
        $this->actingAs($a['user'], 'web');
        $this->patchJson('/projects/garden-planner/status', ['status' => 'smooth'])->assertSuccessful();
        $this->assertSame('ui', $this->board($a['user'], 'garden-planner')['statusHistory'][0]['source']);
    }
}
