<?php

namespace Tests\Feature\MultiUser;

use App\Models\Agent;
use App\Models\NextStep;
use App\Models\Project;
use App\Models\StatusUpdate;
use App\Models\Ticket;
use App\Models\UsageDay;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tickets\Actor;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\Feature\UsageImportTest;
use Tests\TestCase;

/** Two workspaces over the token API: another user's token never sees or touches Tilen's data, and vice versa. */
class WorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase, TicketHelpers;

    private string $tilen;

    private string $grok;

    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
        // Tilen's side, like live: Captain's API user (member) with the 'ceo' token bound by the backfill.
        $captain = $this->workspaceUser(['email' => 'captain@lantis.internal']);
        $ceo = $captain->createToken('ceo', ['ceo']);
        $ceo->accessToken->forceFill(['workspace_id' => app(CurrentWorkspace::class)->default()->id])->save();
        $this->tilen = $ceo->plainTextToken;

        // Another user who owns their own workspace; their token resolves via ownership (no workspace_id on it).
        $owner = User::factory()->create(['email' => 'grok@example.test']);
        $this->other = Workspace::query()->create(['slug' => 'grok', 'name' => 'Grok', 'owner_user_id' => $owner->id]);
        $this->grok = $owner->createToken('grok-bot', ['*'])->plainTextToken;

        $this->send($this->tilen, 'POST', '/api/projects/'.self::CA.'/tickets', ['title' => 'Tilen only', 'actor' => self::JANICE])->assertCreated();
        $this->send($this->tilen, 'POST', '/api/tickets/CA-1/comments', ['body' => 'secret', 'actor' => self::JANICE])->assertCreated();
        $this->send($this->tilen, 'POST', '/api/status-updates', ['project_id' => self::MW, 'agent_id' => self::ANATOLY, 'body' => 'Tilen status'])->assertCreated();
    }

    private function send(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, $uri, $data, ['Authorization' => 'Bearer '.$token]);
    }

    /** POST /api/agents is Captain-only (agent-william, Tilen's roster): another workspace's first agent is seeded. */
    private function grokAgent(): void
    {
        app(CurrentWorkspace::class)->runAs($this->other, fn () => Agent::query()->create(['id' => 'agent-grok', 'name' => 'Grok', 'role_label' => 'Bot']));
    }

    private function usage(string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->call('POST', '/api/usage/days', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], UsageImportTest::fixture());
    }

    #[Test]
    public function tilens_existing_token_keeps_full_access_to_his_workspace(): void
    {
        $this->send($this->tilen, 'GET', '/api/projects')->assertOk()->assertJsonCount(2, 'data');
        $this->send($this->tilen, 'GET', '/api/tickets/CA-1')->assertOk()->assertJsonPath('data.title', 'Tilen only');
        $this->send($this->tilen, 'GET', '/api/agents')->assertOk()->assertJsonCount(5, 'data');
        $this->usage($this->tilen)->assertCreated();
    }

    #[Test]
    public function another_workspace_sees_nothing_of_tilens_projects_tickets_and_status(): void
    {
        $this->send($this->grok, 'GET', '/api/projects')->assertOk()->assertJsonCount(0, 'data');
        $this->send($this->grok, 'GET', '/api/projects/'.self::CA)->assertNotFound();
        $this->send($this->grok, 'GET', '/api/projects/concierge-avant')->assertNotFound();
        $this->send($this->grok, 'GET', '/api/projects/'.self::CA.'/board')->assertNotFound();
        $this->send($this->grok, 'GET', '/api/projects/'.self::CA.'/status')->assertNotFound();
        $this->send($this->grok, 'GET', '/api/tickets')->assertOk()->assertJsonCount(0, 'data');
        $this->send($this->grok, 'GET', '/api/tickets/CA-1')->assertNotFound();
        $this->send($this->grok, 'GET', '/api/tickets/CA-1/comments')->assertNotFound();
        $this->send($this->grok, 'GET', '/api/ticket-comments/feed')->assertOk()->assertJsonCount(0, 'data');
        $this->send($this->grok, 'GET', '/api/status-updates')->assertOk()->assertJsonCount(0, 'data');
        $this->send($this->grok, 'GET', '/api/agents')->assertOk()->assertJsonCount(0, 'data');
        $this->send($this->grok, 'GET', '/api/labels')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function another_workspace_cannot_write_into_tilens(): void
    {
        $this->send($this->grok, 'PATCH', '/api/tickets/CA-1', ['title' => 'hijack', 'actor' => self::JANICE])->assertNotFound();
        $this->send($this->grok, 'POST', '/api/tickets/CA-1/move', ['status' => 'done', 'actor' => self::JANICE])->assertNotFound();
        $this->send($this->grok, 'POST', '/api/tickets/CA-1/comments', ['body' => 'x', 'actor' => self::JANICE])->assertNotFound();
        $this->send($this->grok, 'POST', '/api/projects/'.self::CA.'/tickets', ['title' => 'x', 'actor' => self::JANICE])->assertNotFound();
        $this->send($this->grok, 'PATCH', '/api/projects/'.self::CA.'/status', ['status' => 'smooth', 'actor' => self::JANICE])->assertNotFound();
        $this->send($this->grok, 'POST', '/api/status-updates', ['project_id' => self::MW, 'agent_id' => self::ANATOLY, 'body' => 'x'])->assertNotFound();
        // Tilen's agents do not exist for it: the (workspace-aware) exists rule fails, nothing is written.
        $this->grokAgent();
        $this->send($this->grok, 'POST', '/api/projects', ['name' => 'X', 'slug' => 'x-grok', 'owner_agent_id' => self::ANATOLY, 'actor' => 'agent-grok'])
            ->assertUnprocessable()->assertJsonValidationErrors('owner_agent_id');

        $this->assertSame('Tilen only', Ticket::query()->find(Ticket::findByRef('CA-1')->id)->title);
        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame(2, Project::query()->count());
    }

    #[Test]
    public function each_workspace_has_its_own_projects_tickets_and_usage(): void
    {
        $this->grokAgent();
        $this->send($this->grok, 'POST', '/api/projects', ['id' => 'proj-grok', 'name' => 'Grok Lab', 'slug' => 'grok-lab', 'owner_agent_id' => 'agent-grok', 'actor' => 'agent-grok'])->assertCreated();
        $key = $this->send($this->grok, 'POST', '/api/projects/proj-grok/tickets', ['title' => 'Grok ticket', 'actor' => 'agent-grok'])->assertCreated()->json('data.key');
        $this->usage($this->tilen)->assertCreated();
        $this->usage($this->grok)->assertCreated(); // same date, other workspace: its own row

        $this->send($this->grok, 'GET', '/api/projects')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'proj-grok');
        $this->send($this->tilen, 'GET', '/api/projects')->assertOk()->assertJsonCount(2, 'data');
        $this->send($this->tilen, 'GET', "/api/tickets/$key")->assertNotFound();
        $this->send($this->tilen, 'GET', '/api/agents')->assertOk()->assertJsonMissing(['id' => 'agent-grok']);

        $this->assertSame($this->other->id, Project::query()->find('proj-grok')->workspace_id);
        $this->assertSame(2, UsageDay::query()->count());
        $this->assertSame(1, UsageDay::query()->where('workspace_id', $this->other->id)->count());
    }

    #[Test]
    public function the_gate_session_shows_only_tilens_workspace(): void
    {
        $this->grokAgent();
        $this->send($this->grok, 'POST', '/api/projects', ['id' => 'proj-grok', 'name' => 'Grok Lab', 'slug' => 'grok-lab', 'owner_agent_id' => 'agent-grok', 'actor' => 'agent-grok'])->assertCreated();

        $this->gate()->get('/projects/grok-lab')->assertNotFound();
        $this->gate()->get('/projects/concierge-avant')->assertOk();
        $this->gate()->get('/')->assertOk()->assertDontSee('Grok Lab');
    }

    #[Test]
    public function tokens_without_a_workspace_or_membership_are_refused(): void
    {
        $loner = User::factory()->create()->createToken('x')->plainTextToken;
        $this->send($loner, 'GET', '/api/projects')->assertForbidden();

        // Bound to Tilen's workspace but the user is no member (e.g. removed): refused.
        $stranger = User::factory()->create()->createToken('x');
        $stranger->accessToken->forceFill(['workspace_id' => app(CurrentWorkspace::class)->default()->id])->save();
        $this->send($stranger->plainTextToken, 'GET', '/api/projects')->assertForbidden();
    }

    #[Test]
    public function scoped_abilities_limit_new_tokens_and_legacy_abilities_keep_everything(): void
    {
        $owner = $this->other->owner;
        $this->grokAgent();
        $reader = $owner->createToken('reader', ['boards:read'])->plainTextToken;
        $usage = $owner->createToken('usage', ['usage:write'])->plainTextToken;
        $writer = $owner->createToken('writer', ['boards:read', 'boards:write', 'agents:write'])->plainTextToken;

        $this->send($reader, 'GET', '/api/projects')->assertOk();
        $this->send($reader, 'POST', '/api/agents', ['id' => 'agent-grok', 'name' => 'Grok', 'role_label' => 'Bot'])->assertForbidden();
        $this->usage($reader)->assertForbidden();
        $this->send($usage, 'GET', '/api/projects')->assertForbidden();
        $this->usage($usage)->assertCreated();
        // Past the ability check (then the Captain-only rule answers 403 with its own message).
        $this->send($writer, 'POST', '/api/agents', ['id' => 'agent-x', 'name' => 'X', 'actor' => 'agent-grok'])->assertForbidden()
            ->assertJsonPath('message', 'Only Captain ('.Actor::CAPTAIN.') can add agents.');
        $this->send($writer, 'POST', '/api/status-updates', ['project_id' => 'x', 'agent_id' => 'agent-grok', 'body' => 'b'])->assertForbidden()
            ->assertJsonPath('message', "This token lacks the 'status:write' ability.");
        $this->send($this->tilen, 'POST', '/api/status-updates', ['project_id' => self::MW, 'agent_id' => self::ANATOLY, 'body' => 'b'])->assertCreated();
    }

    #[Test]
    public function a_foreign_project_on_status_updates_answers_exactly_like_a_missing_one(): void
    {
        $this->grokAgent();
        $foreign = $this->send($this->grok, 'POST', '/api/status-updates', ['project_id' => self::MW, 'agent_id' => 'agent-grok', 'body' => 'x']);
        $missing = $this->send($this->grok, 'POST', '/api/status-updates', ['project_id' => 'proj-missing', 'agent_id' => 'agent-grok', 'body' => 'x']);

        $foreign->assertNotFound();
        $missing->assertNotFound();
        // Same body shape; the message only echoes the caller's own id back (debug traces aside).
        $this->assertSame(array_keys($missing->json()), array_keys($foreign->json()));
        $this->assertSame($missing->json('message'), str_replace(self::MW, 'proj-missing', $foreign->json('message')));
        $this->assertSame(0, app(CurrentWorkspace::class)->runAs($this->other, fn () => StatusUpdate::query()->count()));
    }

    #[Test]
    public function a_token_cannot_file_a_status_under_another_workspaces_agent(): void
    {
        $this->grokAgent();
        $tilens = StatusUpdate::query()->count();
        app(CurrentWorkspace::class)->runAs($this->other, fn () => Project::query()->create(['id' => 'proj-grok', 'name' => 'Grok Lab', 'slug' => 'grok-lab', 'owner_agent_id' => 'agent-grok']));

        // Grok's own project, Tilen's agent: rejected exactly like an unknown agent.
        $foreign = $this->send($this->grok, 'POST', '/api/status-updates', ['project_id' => 'proj-grok', 'agent_id' => self::ANATOLY, 'body' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('agent_id');
        $missing = $this->send($this->grok, 'POST', '/api/status-updates', ['project_id' => 'proj-grok', 'agent_id' => 'agent-missing', 'body' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('agent_id');
        $this->assertSame($missing->json(), $foreign->json());
        // And the other way round: Tilen's token, Tilen's project, Grok's agent.
        $this->send($this->tilen, 'POST', '/api/status-updates', ['project_id' => self::MW, 'agent_id' => 'agent-grok', 'body' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('agent_id');

        $this->assertSame($tilens, StatusUpdate::query()->count());
        $this->assertSame(0, app(CurrentWorkspace::class)->runAs($this->other, fn () => StatusUpdate::query()->count()));
    }

    #[Test]
    public function next_steps_reject_a_foreign_project_or_agent_like_a_missing_one(): void
    {
        $this->grokAgent();
        $tilens = NextStep::query()->count();
        $foreign = $this->send($this->grok, 'POST', '/api/next-steps', ['project_id' => self::MW, 'agent_id' => self::ANATOLY, 'kind' => 'blocker', 'text' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['project_id', 'agent_id']);
        $missing = $this->send($this->grok, 'POST', '/api/next-steps', ['project_id' => 'proj-missing', 'agent_id' => 'agent-missing', 'kind' => 'blocker', 'text' => 'x'])
            ->assertUnprocessable();
        $this->assertSame($missing->json(), $foreign->json());
        $this->assertSame($tilens, NextStep::query()->count());
        $this->assertSame(0, app(CurrentWorkspace::class)->runAs($this->other, fn () => NextStep::query()->count()));
    }

    #[Test]
    public function api_is_rate_limited_to_120_requests_a_minute_per_token(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->send($this->grok, 'GET', '/api/agents')->assertOk();
        }
        $this->send($this->grok, 'GET', '/api/agents')->assertStatus(429)
            ->assertJsonPath('message', 'Too many API requests for this token. Try again in a minute.');
        // Keyed by token id: another token is unaffected.
        $this->send($this->tilen, 'GET', '/api/agents')->assertOk();
    }
}
