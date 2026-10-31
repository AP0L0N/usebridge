<?php

namespace Tests\Feature\MultiUser;

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\AccountService;
use App\Services\Auth\AdminUsers;
use App\Services\Auth\ApiTokens;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\Feature\UsageImportTest;
use Tests\TestCase;

/**
 * Relayed decision 2 (docs/MULTI-USER-PLAN.md §5): Tilen, owner and is_admin, sees nothing of another user's workspace,
 * neither with his own token nor with his own web session: projects, boards, tickets, statuses and usage answer 404
 * (or come back empty). Admin is the user list + disable/enable, nothing else.
 */
class AdminIsolationTest extends TestCase
{
    use RefreshDatabase, TicketHelpers;

    private User $tilen;

    private string $tilenToken;

    private User $marta;

    private string $martaToken;

    private string $key;

    private string $usageAgent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets('2026-10-20 10:00:00'); // Tilen's seeded projects; usage "yesterday" = the fixture day
        Notification::fake();
        $tilens = app(CurrentWorkspace::class)->default();
        $this->tilen = $tilens->owner;
        $this->assertTrue($this->tilen->is_admin && $this->tilen->hasVerifiedEmail());
        $this->tilenToken = app(ApiTokens::class)->create($this->tilen, $tilens, 'tilen-laptop', 'write', null)['plain_text'];

        $this->marta = app(AccountService::class)->register('Marta', 'marta@example.test', 'correct horse battery', 'Marta Labs');
        $workspace = $this->marta->ownedWorkspaces()->first();
        app(CurrentWorkspace::class)->runAs($workspace, fn () => Agent::query()->create(['id' => 'agent-marta', 'name' => 'Marta Bot', 'role_label' => 'PM']));
        $this->martaToken = $this->marta->createToken('seed', ['*'])->plainTextToken;
        $this->bearer($this->martaToken, 'POST', '/api/projects', ['id' => 'proj-marta', 'name' => 'Marta Lab', 'slug' => 'marta-lab', 'owner_agent_id' => 'agent-marta', 'actor' => 'agent-marta'])->assertCreated();
        $this->key = $this->bearer($this->martaToken, 'POST', '/api/projects/proj-marta/tickets', ['title' => 'Marta secret ticket', 'actor' => 'agent-marta'])->assertCreated()->json('data.key');
        $this->bearer($this->martaToken, 'POST', "/api/tickets/{$this->key}/comments", ['body' => 'Marta secret comment', 'actor' => 'agent-marta'])->assertCreated();
        $this->bearer($this->martaToken, 'POST', '/api/status-updates', ['project_id' => 'proj-marta', 'agent_id' => 'agent-marta', 'body' => 'Marta secret status'])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->call('POST', '/api/usage/days', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$this->martaToken}",
        ], UsageImportTest::fixture('2037-10-19'))->assertCreated();
        preg_match('/"agent_id":\s*"([^"]+)"/', UsageImportTest::fixture('2037-10-19'), $m);
        $this->usageAgent = $m[1];
    }

    private function bearer(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, $uri, $data, ['Authorization' => "Bearer $token"]);
    }

    private function asTilen(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->tilen, 'web');
    }

    #[Test]
    public function tilens_token_gets_404_or_nothing_on_another_users_data(): void
    {
        $t = $this->tilenToken;
        foreach ([
            '/api/projects/proj-marta', '/api/projects/marta-lab', '/api/projects/proj-marta/board', '/api/projects/proj-marta/status',
            '/api/projects/proj-marta/tickets', '/api/projects/proj-marta/activity', "/api/tickets/{$this->key}",
            "/api/tickets/{$this->key}/comments", "/api/tickets/{$this->key}/events",
        ] as $uri) {
            $this->bearer($t, 'GET', $uri)->assertNotFound();
        }
        $this->bearer($t, 'GET', '/api/status-updates?project_id=proj-marta')->assertOk()->assertJsonCount(0, 'data');
        foreach (['/api/projects', '/api/tickets', '/api/status-updates', '/api/agents', '/api/ticket-comments/feed', '/api/labels'] as $uri) {
            $body = $this->bearer($t, 'GET', $uri)->assertOk()->getContent();
            foreach (['proj-marta', 'Marta', $this->key] as $secret) {
                $this->assertStringNotContainsString($secret, $body, "$uri leaks $secret");
            }
        }
        // Writes into it are 404 as well.
        $this->bearer($t, 'PATCH', "/api/tickets/{$this->key}", ['title' => 'x', 'actor' => self::JANICE])->assertNotFound();
        $this->bearer($t, 'POST', '/api/status-updates', ['project_id' => 'proj-marta', 'agent_id' => self::JANICE, 'body' => 'x'])->assertNotFound();

        // Control: Marta's own token sees it.
        $this->bearer($this->martaToken, 'GET', "/api/tickets/{$this->key}")->assertOk();
    }

    #[Test]
    public function tilens_web_session_gets_404_or_nothing_on_another_users_data(): void
    {
        $this->asTilen()->get('/projects/marta-lab')->assertNotFound();
        $this->asTilen()->get("/projects/marta-lab/tickets/{$this->key}")->assertNotFound();
        $this->asTilen()->postJson('/projects/marta-lab/tickets', ['title' => 'x'])->assertNotFound();
        $this->asTilen()->patchJson("/projects/marta-lab/tickets/{$this->key}", ['title' => 'x'])->assertNotFound();
        $this->asTilen()->postJson('/projects/marta-lab/status-updates', ['body' => 'x'])->assertNotFound();
        $this->asTilen()->patchJson('/projects/marta-lab/status', ['status' => 'smooth'])->assertNotFound();
        $this->asTilen()->get("/usage/agents/{$this->usageAgent}?range=7")->assertNotFound();
        foreach (['/', '/usage?range=7', '/usage?day=2037-10-19'] as $uri) {
            $body = $this->asTilen()->get($uri)->assertOk()->getContent();
            foreach (['Marta Lab', 'proj-marta', 'Marta secret', $this->usageAgent] as $secret) {
                $this->assertStringNotContainsString($secret, $body, "$uri leaks $secret");
            }
        }
        // His own workspace is all he gets.
        $this->asTilen()->get('/projects/blue-harbor')->assertOk();

        // Control: Marta's web session sees her own.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->marta, 'web')->get('/projects/marta-lab')->assertOk();
        $this->actingAs($this->marta, 'web')->get("/usage/agents/{$this->usageAgent}?range=7")->assertOk();
        $this->actingAs($this->marta, 'web')->get('/projects/blue-harbor')->assertNotFound();
    }

    #[Test]
    public function a_leftover_gate_flag_does_not_widen_a_members_view(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->marta, 'web')->withSession(['bridge_gate_unlocked' => true])->get('/projects/blue-harbor')->assertNotFound();
    }

    #[Test]
    public function admin_json_is_the_user_list_with_exactly_the_allowed_keys(): void
    {
        $body = $this->asTilen()->getJson('/admin/users')->assertOk();
        $this->assertSame(['data'], array_keys($body->json()));
        foreach ($body->json('data') as $row) {
            $this->assertSame(['id', 'name', 'email', 'created_at', 'last_login_at', 'status'], array_keys($row));
        }
        $this->assertSame(AdminUsers::KEYS, ['id', 'name', 'email', 'created_at', 'last_login_at', 'status']);
        foreach (['Marta Lab', 'proj-marta', $this->key, 'marta-labs'] as $secret) {
            $this->assertStringNotContainsString($secret, $body->getContent());
        }
    }

    #[Test]
    public function disabled_user_is_refused_and_blocked_until_enabled(): void
    {
        $this->marta->markEmailAsVerified();
        $plain = app(ApiTokens::class)->create($this->marta, Workspace::query()->where('owner_user_id', $this->marta->id)->first(), 'grok', 'read', null)['plain_text'];
        DB::table('sessions')->insert(['id' => 'marta-laptop', 'user_id' => $this->marta->id, 'payload' => '', 'last_activity' => time()]);

        $this->asTilen()->postJson("/admin/users/{$this->marta->id}/disable")->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->marta->id)->count());
        $this->bearer($plain, 'GET', '/api/projects')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->postJson('/login', ['email' => 'marta@example.test', 'password' => 'correct horse battery'])->assertForbidden();

        $this->asTilen()->postJson("/admin/users/{$this->marta->id}/enable")->assertOk();
        $this->bearer($plain, 'GET', '/api/projects')->assertOk()->assertJsonPath('data.0.id', 'proj-marta');
    }
}
