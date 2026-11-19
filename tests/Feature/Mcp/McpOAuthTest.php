<?php

namespace Tests\Feature\Mcp;

use App\Models\Agent;
use App\Models\McpOAuthClient;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\AccountService;
use App\Services\Auth\McpOAuth;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\McpHelpers;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/**
 * OAuth 2.1 for /mcp (docs/MCP-OAUTH.md): discovery, consent, authorization code + PKCE, client authentication,
 * refresh rotation, dynamic registration, and /mcp accepting OAuth access tokens next to Sanctum tokens.
 */
class McpOAuthTest extends TestCase
{
    use McpHelpers, RefreshDatabase, TicketHelpers;

    private const ROOT = 'https://bridge.example.test';

    private const REDIRECT = 'https://gemini.example.test/oauth/callback';

    private const VERIFIER = 'a-verifier-of-at-least-43-characters-0123456789';

    private const PASSWORD = 'correct horse battery';

    private User $tilen;

    private User $marta;

    private McpOAuthClient $client;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
        Notification::fake();
        config(['app.url' => self::ROOT]);
        $this->tilen = app(CurrentWorkspace::class)->default()->owner;

        $this->marta = app(AccountService::class)->register('Marta', 'marta@example.test', self::PASSWORD, 'Marta Labs');
        $this->marta->markEmailAsVerified();
        $workspace = Workspace::query()->where('owner_user_id', $this->marta->id)->firstOrFail();
        app(CurrentWorkspace::class)->runAs($workspace, fn () => Agent::query()->create(['id' => 'agent-marta', 'name' => 'Marta Bot', 'role_label' => 'PM']));
        $seed = $this->marta->createToken('seed', ['*'])->plainTextToken;
        $this->bearer($seed, 'POST', '/api/projects', ['id' => 'proj-marta', 'name' => 'Marta Lab', 'slug' => 'marta-lab', 'owner_agent_id' => 'agent-marta', 'actor' => 'agent-marta'])->assertCreated();

        ['client' => $this->client, 'secret' => $this->secret] = app(McpOAuth::class)->createClient('Gemini', [self::REDIRECT], confidential: true);
    }

    /** @return array<string, string> a valid authorization request of $client */
    private function request(?McpOAuthClient $client = null, array $override = []): array
    {
        return array_filter([
            'client_id' => ($client ?? $this->client)->id,
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'state' => 'st-123',
            'code_challenge' => McpOAuth::challengeFor(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'scope' => McpOAuth::SCOPE,
            'resource' => self::ROOT.'/mcp',
            ...$override,
        ], fn ($value) => $value !== null);
    }

    /** The query of the URL the consent screen sends the browser to. */
    private function redirectQuery(TestResponse $response): array
    {
        $redirect = (string) ($response->headers->get('Location') ?? $response->json('redirect'));
        $this->assertStringStartsWith(self::REDIRECT.'?', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        return $query;
    }

    /** $user approves on the consent screen; the code the client receives. */
    private function approve(User $user, ?McpOAuthClient $client = null): string
    {
        $response = $this->actingAs($user, 'web')->postJson('/oauth/authorize', [...$this->request($client), 'decision' => 'approve'])->assertOk();
        $query = $this->redirectQuery($response);
        $this->assertSame('st-123', $query['state']);

        return $query['code'];
    }

    private function token(array $data, array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->post('/oauth/token', $data, ['Accept' => 'application/json', ...$headers]);
    }

    private function exchange(string $code, array $override = []): TestResponse
    {
        return $this->token([
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER,
            'client_id' => $this->client->id, 'client_secret' => $this->secret, ...$override,
        ]);
    }

    /** @return array{access_token: string, refresh_token: string} Marta connected Gemini */
    private function connected(): array
    {
        return $this->exchange($this->approve($this->marta))->assertOk()->json();
    }

    #[Test]
    public function discovery_metadata_names_the_mcp_resource_and_the_endpoints_on_app_url(): void
    {
        foreach (['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp'] as $path) {
            $this->getJson($path)->assertOk()->assertExactJson([
                'resource' => self::ROOT.'/mcp',
                'authorization_servers' => [self::ROOT],
                'scopes_supported' => ['mcp:use'],
                'bearer_methods_supported' => ['header'],
                'resource_name' => 'Bridge',
            ]);
        }
        foreach (['/.well-known/oauth-authorization-server', '/.well-known/oauth-authorization-server/mcp'] as $path) {
            $this->getJson($path)->assertOk()->assertExactJson([
                'issuer' => self::ROOT,
                'authorization_endpoint' => self::ROOT.'/oauth/authorize',
                'token_endpoint' => self::ROOT.'/oauth/token',
                'registration_endpoint' => self::ROOT.'/oauth/register',
                'response_types_supported' => ['code'],
                'grant_types_supported' => ['authorization_code', 'refresh_token'],
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none'],
                'scopes_supported' => ['mcp:use'],
            ]);
        }
    }

    #[Test]
    public function mcp_without_a_valid_token_answers_401_with_the_discovery_challenge(): void
    {
        $metadata = 'resource_metadata="'.self::ROOT.'/.well-known/oauth-protected-resource"';

        $this->mcp(null, 'tools/list')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", '.$metadata);
        foreach (['brg_oat_not-a-token', '1|not-a-token'] as $bearer) {
            $this->mcp($bearer, 'tools/list')->assertUnauthorized()
                ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token", '.$metadata);
        }
    }

    #[Test]
    public function a_guest_logs_in_and_returns_to_the_consent_screen(): void
    {
        $url = '/oauth/authorize?'.http_build_query($this->request());

        $this->get($url)->assertRedirect('/login');
        $redirect = $this->postJson('/login', ['email' => 'marta@example.test', 'password' => self::PASSWORD])->assertOk()->json('redirect');
        $this->assertStringStartsWith('/oauth/authorize?', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertEquals($this->request(), $query);
        // Only once: the next login goes to the boards again.
        $this->postJson('/logout')->assertOk();
        $this->postJson('/login', ['email' => 'marta@example.test', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('redirect', route('portfolio'));
    }

    #[Test]
    public function the_consent_screen_shows_the_client_and_its_redirect_uri(): void
    {
        $this->actingAs($this->marta, 'web')->get('/oauth/authorize?'.http_build_query($this->request()))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertInertia(fn (Assert $page) => $page->component('Auth/OAuthConsent')
                ->where('client.name', 'Gemini')
                ->where('client.redirectUri', self::REDIRECT)
                ->where('client.dynamic', false)
                ->where('account.email', 'marta@example.test')
                ->where('account.workspace', 'Marta Labs')
                ->where('emailVerified', true)
                ->where('params.state', 'st-123')
                ->where('error', null));
    }

    #[Test]
    public function an_unknown_client_or_redirect_uri_gets_an_error_page_and_no_redirect(): void
    {
        foreach ([['client_id' => 'nope'], ['redirect_uri' => self::REDIRECT.'/other'], ['redirect_uri' => null]] as $override) {
            $this->actingAs($this->marta, 'web')->get('/oauth/authorize?'.http_build_query($this->request(null, $override)))
                ->assertStatus(400)
                ->assertInertia(fn (Assert $page) => $page->component('Auth/OAuthConsent')->where('client', null)->whereNot('error', null));
            $this->postJson('/oauth/authorize', [...$this->request(null, $override), 'decision' => 'approve'])
                ->assertStatus(400)->assertJsonMissingPath('redirect');
        }
    }

    #[Test]
    public function pkce_with_s256_is_required_on_the_authorization_request(): void
    {
        $bad = [
            'plain' => ['code_challenge_method' => 'plain', 'code_challenge' => self::VERIFIER],
            'no method' => ['code_challenge_method' => null],
            'no challenge' => ['code_challenge' => null],
        ];
        foreach ($bad as $override) {
            $query = $this->redirectQuery($this->actingAs($this->marta, 'web')->get('/oauth/authorize?'.http_build_query($this->request(null, $override))));
            $this->assertSame('invalid_request', $query['error']);
            $this->assertSame('st-123', $query['state']);

            $query = $this->redirectQuery($this->postJson('/oauth/authorize', [...$this->request(null, $override), 'decision' => 'approve'])->assertOk());
            $this->assertSame('invalid_request', $query['error']);
            $this->assertArrayNotHasKey('code', $query);
        }
        $this->assertDatabaseCount('mcp_oauth_codes', 0);

        $query = $this->redirectQuery($this->get('/oauth/authorize?'.http_build_query($this->request(null, ['response_type' => 'token']))));
        $this->assertSame('unsupported_response_type', $query['error']);
        $query = $this->redirectQuery($this->get('/oauth/authorize?'.http_build_query($this->request(null, ['resource' => 'https://other.example.test/mcp']))));
        $this->assertSame('invalid_target', $query['error']);
    }

    #[Test]
    public function denying_returns_access_denied_and_no_code(): void
    {
        $query = $this->redirectQuery($this->actingAs($this->marta, 'web')->postJson('/oauth/authorize', [...$this->request(), 'decision' => 'deny'])->assertOk());

        $this->assertSame(['error' => 'access_denied', 'error_description' => 'The user denied the request.', 'state' => 'st-123'], $query);
        $this->assertDatabaseCount('mcp_oauth_codes', 0);
    }

    #[Test]
    public function an_unverified_user_cannot_approve(): void
    {
        $this->marta->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($this->marta, 'web')->get('/oauth/authorize?'.http_build_query($this->request()))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('emailVerified', false));
        $this->postJson('/oauth/authorize', [...$this->request(), 'decision' => 'approve'])->assertForbidden()->assertJsonPath('verified', false);
        $this->assertDatabaseCount('mcp_oauth_codes', 0);
    }

    #[Test]
    public function an_approved_code_becomes_tokens_that_act_in_the_approving_users_workspace(): void
    {
        $tokens = $this->exchange($this->approve($this->marta))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();

        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertSame(3600, $tokens['expires_in']);
        $this->assertSame('mcp:use', $tokens['scope']);
        $this->assertStringStartsWith('brg_oat_', $tokens['access_token']);
        $this->assertStringStartsWith('brg_ort_', $tokens['refresh_token']);

        $this->mcp($tokens['access_token'], 'initialize', [
            'protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass, 'clientInfo' => ['name' => 'gemini', 'version' => '1'],
        ])->assertOk()->assertJsonPath('result.serverInfo.name', 'Bridge');
        // Marta's workspace only: her project, never Tilen's.
        $this->assertSame(['proj-marta'], array_column($this->tool($tokens['access_token'], 'list_projects')['data'], 'id'));
        $this->assertStringContainsString('Unknown project', $this->toolError($tokens['access_token'], 'get_board', ['project' => self::BH]));
        // Writes are recorded as the connection's own agent, in her workspace.
        $ticket = $this->tool($tokens['access_token'], 'create_ticket', ['project' => 'proj-marta', 'title' => 'From Gemini'])['data'];
        $grant = $this->marta->tokens()->where('name', 'Gemini (OAuth)')->sole();
        $this->assertSame($grant->agent_id, $ticket['created_by']);
        $this->assertSame(Workspace::query()->where('owner_user_id', $this->marta->id)->value('id'), (int) $grant->workspace_id);
        $this->assertNotNull($grant->fresh()->last_used_at);
        // The hashes are stored, never the tokens.
        $this->assertDatabaseMissing('mcp_oauth_tokens', ['access_token_hash' => $tokens['access_token']]);
        $this->assertDatabaseHas('mcp_oauth_tokens', ['access_token_hash' => hash('sha256', $tokens['access_token'])]);
    }

    #[Test]
    public function sanctum_tokens_keep_working_on_mcp(): void
    {
        $this->connected();
        $workspace = app(CurrentWorkspace::class)->default();
        $token = $this->scopedToken($this->tilen, $workspace, 'read');

        $ids = array_column($this->tool($token, 'list_projects')['data'], 'id');

        $this->assertContains(self::BH, $ids);
        $this->assertNotContains('proj-marta', $ids);
    }

    #[Test]
    public function the_code_needs_the_right_verifier_and_the_exact_redirect_uri(): void
    {
        $bad = [
            'wrong verifier' => ['code_verifier' => str_repeat('x', 43)],
            'no verifier' => ['code_verifier' => null],
            'the challenge instead of the verifier' => ['code_verifier' => McpOAuth::challengeFor(self::VERIFIER)],
            'other redirect uri' => ['redirect_uri' => self::REDIRECT.'/'],
            'no redirect uri' => ['redirect_uri' => null],
        ];
        foreach ($bad as $case => $override) {
            $this->exchange($this->approve($this->marta), $override)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
            $this->assertDatabaseCount('mcp_oauth_tokens', 0);
            $this->assertSame(0, $this->marta->tokens()->where('name', 'Gemini (OAuth)')->count(), $case);
        }
        $this->exchange('not-a-code')->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->token(['grant_type' => 'password', 'client_id' => $this->client->id, 'client_secret' => $this->secret])
            ->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
    }

    #[Test]
    public function a_code_works_once_and_expires(): void
    {
        $code = $this->approve($this->marta);
        $tokens = $this->exchange($code)->assertOk()->json();

        // Replay: refused, and the connection made from the code is revoked.
        $this->exchange($code)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->mcp($tokens['access_token'], 'tools/list')->assertUnauthorized();
        $this->assertSame(0, $this->marta->tokens()->where('name', 'Gemini (OAuth)')->count());

        $code = $this->approve($this->marta);
        $this->travel(McpOAuth::CODE_MINUTES + 1)->minutes();
        $this->exchange($code)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    #[Test]
    public function a_confidential_client_must_send_its_secret_by_post_or_basic(): void
    {
        $basic = fn (string $secret) => ['Authorization' => 'Basic '.base64_encode($this->client->id.':'.$secret)];

        $this->exchange($this->approve($this->marta), ['client_secret' => 'wrong'])->assertUnauthorized()->assertJsonPath('error', 'invalid_client');
        $this->exchange($this->approve($this->marta), ['client_secret' => null])->assertUnauthorized()->assertJsonPath('error', 'invalid_client');
        $this->exchange($this->approve($this->marta), ['client_id' => 'unknown'])->assertUnauthorized();
        $this->exchange($this->approve($this->marta), ['client_id' => null, 'client_secret' => null])->assertUnauthorized();
        $code = $this->approve($this->marta);
        $withoutClient = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER];
        $this->token($withoutClient, $basic('wrong'))->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->assertDatabaseCount('mcp_oauth_tokens', 0);

        $this->token($withoutClient, $basic($this->secret))->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
        // Stored hashed.
        $this->assertSame(hash('sha256', $this->secret), $this->client->fresh()->secret_hash);
    }

    #[Test]
    public function a_code_belongs_to_the_client_it_was_issued_to(): void
    {
        ['client' => $other, 'secret' => $otherSecret] = app(McpOAuth::class)->createClient('Other', [self::REDIRECT], confidential: true);

        $this->exchange($this->approve($this->marta), ['client_id' => $other->id, 'client_secret' => $otherSecret])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    #[Test]
    public function a_refresh_rotates_the_pair_and_a_replayed_refresh_token_revokes_the_connection(): void
    {
        $first = $this->connected();
        $refresh = fn (string $token, array $override = []) => $this->token([
            'grant_type' => 'refresh_token', 'refresh_token' => $token, 'client_id' => $this->client->id, 'client_secret' => $this->secret, ...$override,
        ]);

        $refresh($first['refresh_token'], ['client_secret' => 'wrong'])->assertUnauthorized();
        $refresh($first['access_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $second = $refresh($first['refresh_token'])->assertOk()->json();

        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->mcp($first['access_token'], 'tools/list')->assertUnauthorized();
        $this->mcp($second['access_token'], 'tools/list')->assertOk();
        $this->assertSame(1, $this->marta->tokens()->where('name', 'Gemini (OAuth)')->count());

        // The old refresh token again: someone is replaying it, so everything of this connection stops.
        $refresh($first['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->mcp($second['access_token'], 'tools/list')->assertUnauthorized();
        $refresh($second['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    #[Test]
    public function access_tokens_expire_after_an_hour_and_refresh_tokens_after_sixty_days(): void
    {
        $tokens = $this->connected();

        $this->travel(McpOAuth::ACCESS_MINUTES + 1)->minutes();
        $this->mcp($tokens['access_token'], 'tools/list')->assertUnauthorized();
        $this->travel(McpOAuth::REFRESH_DAYS + 1)->days();
        $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $this->client->id, 'client_secret' => $this->secret])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    #[Test]
    public function the_user_disconnects_by_revoking_the_token_in_the_account(): void
    {
        $tokens = $this->connected();
        $grant = $this->marta->tokens()->where('name', 'Gemini (OAuth)')->sole();

        $this->actingAs($this->marta, 'web')->deleteJson('/account/tokens/'.$grant->id)->assertOk();

        $this->mcp($tokens['access_token'], 'tools/list')->assertUnauthorized();
        $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $this->client->id, 'client_secret' => $this->secret])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    #[Test]
    public function a_disabled_users_oauth_token_gets_403_like_a_sanctum_token(): void
    {
        $tokens = $this->connected();
        $this->marta->forceFill(['disabled_at' => now()])->save();

        $this->mcp($tokens['access_token'], 'tools/list')->assertForbidden()->assertJsonPath('message', 'This account is disabled.');
    }

    #[Test]
    public function a_client_registers_itself_as_a_public_client_and_connects_with_pkce_only(): void
    {
        $registered = $this->postJson('/oauth/register', [
            'client_name' => 'Inspector', 'redirect_uris' => [self::REDIRECT], 'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'], 'token_endpoint_auth_method' => 'none',
        ])->assertCreated()->assertJsonPath('token_endpoint_auth_method', 'none')->assertJsonPath('redirect_uris', [self::REDIRECT])
            ->assertJsonMissingPath('client_secret')->json();
        $client = McpOAuthClient::query()->findOrFail($registered['client_id']);
        $this->assertTrue($client->is_dynamic);
        $this->assertFalse($client->isConfidential());

        $this->actingAs($this->marta, 'web')->get('/oauth/authorize?'.http_build_query($this->request($client)))
            ->assertInertia(fn (Assert $page) => $page->where('client.name', 'Inspector')->where('client.dynamic', true));
        $code = $this->approve($this->marta, $client);
        $this->exchange($code, ['client_id' => $client->id, 'client_secret' => null, 'code_verifier' => str_repeat('x', 43)])->assertStatus(400);
        $tokens = $this->exchange($this->approve($this->marta, $client), ['client_id' => $client->id, 'client_secret' => null])->assertOk()->json();
        $this->mcp($tokens['access_token'], 'tools/list')->assertOk();
    }

    #[Test]
    public function registration_refuses_unsafe_redirect_uris_and_confidential_clients(): void
    {
        foreach (['http://evil.example.test/cb', 'javascript:alert(1)', 'https://ok.example.test/cb#fragment', 'not a url'] as $uri) {
            $this->postJson('/oauth/register', ['redirect_uris' => [$uri]])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        }
        $this->postJson('/oauth/register', ['client_name' => 'No redirects'])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        $this->postJson('/oauth/register', ['redirect_uris' => [self::REDIRECT], 'token_endpoint_auth_method' => 'client_secret_post'])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_client_metadata');
        $this->postJson('/oauth/register', ['redirect_uris' => ['http://localhost:8123/callback', 'cursor://anysphere.cursor-mcp/oauth/callback']])->assertCreated();
        $this->assertDatabaseCount('mcp_oauth_clients', 2);
    }

    #[Test]
    public function registration_can_be_switched_off(): void
    {
        config(['bridge.mcp_oauth.dynamic_registration' => false]);

        $this->postJson('/oauth/register', ['redirect_uris' => [self::REDIRECT]])->assertNotFound();
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->assertJsonMissingPath('registration_endpoint');
    }

    #[Test]
    public function the_command_creates_a_confidential_client_and_revokes_it_with_its_connections(): void
    {
        $this->artisan('bridge:mcp-oauth-client', ['name' => 'Gemini 2', '--redirect' => [self::REDIRECT]])
            ->expectsOutputToContain('Client ID:')->expectsOutputToContain('Client secret:')->assertSuccessful();
        $created = McpOAuthClient::query()->where('name', 'Gemini 2')->sole();
        $this->assertTrue($created->isConfidential());
        $this->assertSame([self::REDIRECT], $created->redirect_uris);

        $this->artisan('bridge:mcp-oauth-client', ['name' => 'Bad', '--redirect' => ['http://evil.example.test/cb']])->assertFailed();
        $this->artisan('bridge:mcp-oauth-client', ['name' => 'No redirect'])->assertFailed();
        $this->assertDatabaseCount('mcp_oauth_clients', 2);

        $tokens = $this->connected();
        $this->artisan('bridge:mcp-oauth-client', ['--revoke' => $this->client->id])->expectsOutputToContain('1 connection(s)')->assertSuccessful();

        $this->mcp($tokens['access_token'], 'tools/list')->assertUnauthorized();
        $this->assertSame(0, $this->marta->tokens()->where('name', 'Gemini (OAuth)')->count());
        $this->actingAs($this->marta, 'web')->get('/oauth/authorize?'.http_build_query($this->request()))->assertStatus(400);
        $this->exchange('any', [])->assertUnauthorized();
    }
}
