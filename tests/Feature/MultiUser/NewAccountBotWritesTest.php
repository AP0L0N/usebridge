<?php

namespace Tests\Feature\MultiUser;

use App\Models\Agent;
use App\Models\Project;
use App\Models\User;
use App\Services\Auth\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\McpHelpers;
use Tests\TestCase;

/**
 * Go-live B3 end to end: a brand-new account (sign up -> verify -> create a write token on the Tokens screen) can
 * create, move and comment on tickets through the REST API and MCP without naming an `actor`: the token's own agent
 * (provisioned with the token, name = token name) is recorded. POST /api/agents works without an actor. An explicit
 * actor must be an agent of the caller's workspace, and a token never writes into another workspace. Synthetic data.
 */
class NewAccountBotWritesTest extends TestCase
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

    #[Test]
    public function a_new_account_creates_moves_and_comments_via_rest_and_mcp_without_an_actor(): void
    {
        $a = $this->newAccount('Sam Rivera', 'sam@example.test', 'Garden Planner', 'GP', 'Garden bot');
        $agent = Agent::withoutGlobalScopes()->findOrFail($a['agent']);
        $this->assertSame('Garden bot', $agent->name);
        $this->assertSame($a['user']->homeWorkspace()->id, (int) $agent->workspace_id);
        $this->bearer($a['token'], 'GET', '/api/agents')->assertOk()->assertJsonPath('data.0.id', $a['agent'])->assertJsonCount(1, 'data');

        // REST: create, move, comment; no actor anywhere.
        $t = $this->bearer($a['token'], 'POST', "/api/projects/{$a['project']}/tickets", ['title' => 'Plant tomatoes'])
            ->assertCreated()->assertJsonPath('data.created_by', $a['agent'])->json('data');
        $this->bearer($a['token'], 'POST', "/api/tickets/{$t['key']}/move", ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->bearer($a['token'], 'POST', "/api/tickets/{$t['key']}/comments", ['body' => 'Seedlings ordered.'])->assertCreated();
        $this->bearer($a['token'], 'GET', "/api/tickets/{$t['key']}/comments")->assertOk()
            ->assertJsonPath('data.0.author_id', $a['agent'])->assertJsonPath('data.0.author_name', 'Garden bot');

        // MCP: the same three writes.
        $m = $this->tool($a['token'], 'create_ticket', ['project' => $a['project'], 'title' => 'Water the beds'])['data'];
        $this->assertSame($a['agent'], $m['created_by']);
        $this->assertSame('done', $this->tool($a['token'], 'move_ticket', ['key' => $m['key'], 'status' => 'done'])['data']['status']);
        $this->tool($a['token'], 'comment_ticket', ['key' => $m['key'], 'body' => 'Done for today.']);
        $this->bearer($a['token'], 'GET', "/api/tickets/{$m['key']}/comments")->assertJsonPath('data.0.author_id', $a['agent']);

        // POST /api/agents without an actor (the workspace owner's token); the new agent can then be named as actor.
        $this->bearer($a['token'], 'POST', '/api/agents', ['id' => 'agent-helper-sam', 'name' => 'Helper'])->assertCreated();
        $this->bearer($a['token'], 'POST', "/api/tickets/{$t['key']}/comments", ['body' => 'From the helper.', 'actor' => 'agent-helper-sam'])->assertCreated();
        $this->assertSame($a['user']->homeWorkspace()->id, (int) Agent::withoutGlobalScopes()->findOrFail('agent-helper-sam')->workspace_id);
    }

    #[Test]
    public function an_older_token_without_an_agent_gets_one_on_its_first_write_and_reuses_it(): void
    {
        $a = $this->newAccount('Sam Rivera', 'sam@example.test', 'Garden Planner', 'GP', 'Garden bot');
        $legacy = $a['user']->createToken('Old laptop', ['boards:read', 'boards:write']);
        $legacy->accessToken->forceFill(['workspace_id' => $a['user']->homeWorkspace()->id])->save();
        $plain = $legacy->plainTextToken;
        $this->assertNull($legacy->accessToken->fresh()->agent_id);

        $first = $this->bearer($plain, 'POST', "/api/projects/{$a['project']}/tickets", ['title' => 'One'])->assertCreated()->json('data.created_by');
        $this->assertMatchesRegularExpression('/^agent-old-laptop-[a-z0-9]{6}$/', $first);
        $this->assertSame($first, $legacy->accessToken->fresh()->agent_id);
        $this->bearer($plain, 'POST', "/api/projects/{$a['project']}/tickets", ['title' => 'Two'])->assertJsonPath('data.created_by', $first);
        $this->assertSame(1, Agent::withoutGlobalScopes()->where('name', 'Old laptop')->count());
    }

    #[Test]
    public function a_token_cannot_act_as_or_write_into_another_workspace(): void
    {
        $a = $this->newAccount('Sam Rivera', 'sam@example.test', 'Garden Planner', 'GP', 'Garden bot');
        $b = $this->newAccount('Kim Lee', 'kim@example.test', 'Bake Shop', 'BS', 'Garden bot'); // same token name: distinct agent ids
        $this->assertNotSame($a['agent'], $b['agent']);
        $tb = $this->bearer($b['token'], 'POST', "/api/projects/{$b['project']}/tickets", ['title' => 'Bake bread'])->assertCreated()->json('data');

        // B's token naming A's agent as actor: unknown here (REST and MCP), nothing written.
        $this->bearer($b['token'], 'POST', "/api/projects/{$b['project']}/tickets", ['title' => 'x', 'actor' => $a['agent']])
            ->assertUnprocessable()->assertJsonValidationErrors('actor');
        $this->bearer($b['token'], 'POST', "/api/tickets/{$tb['key']}/comments", ['body' => 'x', 'actor' => $a['agent']])->assertUnprocessable();
        $this->assertStringContainsString('Unknown actor', $this->toolError($b['token'], 'create_ticket', ['project' => $b['project'], 'title' => 'x', 'actor' => $a['agent']]));

        // A's token into B's project / ticket: 404 like a missing one, with or without an actor.
        $this->bearer($a['token'], 'POST', "/api/projects/{$b['project']}/tickets", ['title' => 'x'])->assertNotFound();
        $this->bearer($a['token'], 'POST', "/api/tickets/{$tb['key']}/move", ['status' => 'done'])->assertNotFound();
        $this->bearer($a['token'], 'POST', "/api/tickets/{$tb['key']}/comments", ['body' => 'x', 'actor' => $b['agent']])->assertNotFound();
        $this->assertStringContainsString('Unknown', $this->toolError($a['token'], 'comment_ticket', ['key' => $tb['key'], 'body' => 'x']));
        $this->assertStringContainsString('Unknown', $this->toolError($a['token'], 'move_ticket', ['key' => $tb['key'], 'status' => 'done']));

        // A's agent list never shows B's agent; B's ticket is untouched.
        $this->bearer($a['token'], 'GET', '/api/agents')->assertJsonMissing(['id' => $b['agent']]);
        $this->bearer($b['token'], 'GET', "/api/tickets/{$tb['key']}")->assertJsonPath('data.status', 'todo');
        $this->bearer($b['token'], 'GET', "/api/tickets/{$tb['key']}/comments")->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_member_token_cannot_add_agents_but_can_write_as_its_own_agent(): void
    {
        $a = $this->newAccount('Sam Rivera', 'sam@example.test', 'Garden Planner', 'GP', 'Garden bot');
        $ws = $a['user']->homeWorkspace();
        $member = User::factory()->create(['email' => 'pat@example.test', 'email_verified_at' => now()]);
        $ws->users()->attach($member->id, ['role' => 'member']);
        $plain = $this->scopedToken($member, $ws, 'write', 'Pat bot');

        // A read token gets no agent (it cannot write).
        $read = app(ApiTokens::class)->create($member, $ws, 'Pat reader', 'read', null);
        $this->assertNull($read['token']->agent_id);
        $this->assertSame(0, Agent::withoutGlobalScopes()->where('name', 'Pat reader')->count());

        $this->bearer($plain, 'POST', '/api/agents', ['id' => 'agent-nope', 'name' => 'Nope'])->assertForbidden();
        $this->bearer($plain, 'POST', "/api/projects/{$a['project']}/tickets", ['title' => 'From Pat'])->assertCreated()
            ->assertJsonPath('data.created_by', fn (string $id) => str_starts_with($id, 'agent-pat-bot-'));
    }
}
