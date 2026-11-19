<?php

namespace App\Services\Auth;

use App\Models\McpOAuthClient;
use App\Models\McpOAuthCode;
use App\Models\McpOAuthToken;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * OAuth 2.1 authorization server for the MCP server at /mcp (docs/MCP-OAUTH.md; MCP authorization spec 2025-06-18).
 *
 * - Authorization code + PKCE (S256 only), exact redirect URI match, refresh tokens rotated on every use.
 * - Clients: confidential (client id + secret, bridge:mcp-oauth-client) or public (dynamic registration, PKCE only).
 * - A grant is a normal API token row (ApiTokens, scope write, bound to the approving user's workspace, with its own
 *   agent) whose plaintext nobody ever sees. OAuth access tokens resolve to that row, so /mcp treats both kinds of
 *   bearer the same (workspace, abilities, actor, rate limit), and the user revokes a grant under Account → API tokens.
 * - Codes and tokens are random strings, sha256 at rest (like Sanctum). Every URL is built on APP_URL.
 */
final class McpOAuth
{
    public const SCOPE = 'mcp:use';

    public const CODE_MINUTES = 5;

    public const ACCESS_MINUTES = 60;

    public const REFRESH_DAYS = 60;

    public const ACCESS_PREFIX = 'brg_oat_';

    public const REFRESH_PREFIX = 'brg_ort_';

    public const AUTHORIZE_PATH = '/oauth/authorize';

    /** Schemes a redirect URI may never use (the consent screen navigates to it). */
    private const FORBIDDEN_SCHEMES = ['javascript', 'data', 'vbscript', 'file', 'blob', 'about', 'ftp', 'ws', 'wss'];

    public function __construct(private readonly ApiTokens $tokens, private readonly CurrentWorkspace $current) {}

    /** An absolute URL on APP_URL (never the host of the current request, e.g. behind a proxy). */
    public static function url(string $path = ''): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    /** The protected resource: the MCP server URL. */
    public static function resource(): string
    {
        return self::url('/mcp');
    }

    public static function dynamicRegistrationEnabled(): bool
    {
        return (bool) config('bridge.mcp_oauth.dynamic_registration', true);
    }

    /** @return array<string, mixed> RFC 9728 */
    public static function protectedResourceMetadata(): array
    {
        return [
            'resource' => self::resource(),
            'authorization_servers' => [self::url()],
            'scopes_supported' => [self::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Bridge',
        ];
    }

    /** @return array<string, mixed> RFC 8414 */
    public static function authorizationServerMetadata(): array
    {
        return [
            'issuer' => self::url(),
            'authorization_endpoint' => self::url(self::AUTHORIZE_PATH),
            'token_endpoint' => self::url('/oauth/token'),
            ...(self::dynamicRegistrationEnabled() ? ['registration_endpoint' => self::url('/oauth/register')] : []),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none'],
            'scopes_supported' => [self::SCOPE],
        ];
    }

    /** WWW-Authenticate of a 401 from /mcp: where a client finds the authorization server (RFC 9728 §5.1). */
    public static function challenge(bool $invalidToken): string
    {
        return 'Bearer realm="mcp", '.($invalidToken ? 'error="invalid_token", ' : '')
            .'resource_metadata="'.self::url('/.well-known/oauth-protected-resource').'"';
    }

    /** The path + query of a stored "intended" URL when it is the authorization endpoint (after login), else null. */
    public static function intendedAuthorizeUrl(mixed $intended): ?string
    {
        if (! is_string($intended) || parse_url($intended, PHP_URL_PATH) !== self::AUTHORIZE_PATH) {
            return null;
        }
        $query = parse_url($intended, PHP_URL_QUERY);

        return self::AUTHORIZE_PATH.(is_string($query) && $query !== '' ? '?'.$query : '');
    }

    /** https anywhere, http only on loopback, or an app's own scheme (cursor://…); never a fragment or a script scheme. */
    public static function validRedirectUri(mixed $uri): bool
    {
        if (! is_string($uri) || $uri === '' || strlen($uri) > 2000 || preg_match('/[\s<>"\\\\]/', $uri)) {
            return false;
        }
        $parts = parse_url($uri);
        if ($parts === false || ! isset($parts['scheme']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return match (true) {
            $scheme === 'https' => $host !== '',
            $scheme === 'http' => in_array($host, ['localhost', '127.0.0.1', '[::1]'], true),
            default => preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) === 1 && ! in_array($scheme, self::FORBIDDEN_SCHEMES, true),
        };
    }

    /**
     * @param  list<string>  $redirectUris
     * @return array{client: McpOAuthClient, secret: ?string} the secret is returned once; only its sha256 is stored
     */
    public function createClient(string $name, array $redirectUris, bool $confidential, bool $dynamic = false): array
    {
        $secret = $confidential ? Str::random(48) : null;
        $client = McpOAuthClient::query()->create([
            'id' => (string) Str::uuid(),
            'name' => Str::limit(trim($name), 100, ''),
            'secret_hash' => $secret === null ? null : hash('sha256', $secret),
            'redirect_uris' => array_values(array_unique($redirectUris)),
            'is_dynamic' => $dynamic,
        ]);

        return ['client' => $client, 'secret' => $secret];
    }

    /** Refuse the client from now on and revoke every grant it holds. Returns the number of grants revoked. */
    public function revokeClient(McpOAuthClient $client): int
    {
        $client->forceFill(['revoked_at' => Carbon::now()])->save();
        $grants = McpOAuthToken::query()->where('client_id', $client->id)->distinct()->pluck('personal_access_token_id');
        $revoked = 0;
        foreach ($grants as $grant) {
            $revoked += $this->revokeGrant((int) $grant) ? 1 : 0;
        }

        return $revoked;
    }

    /**
     * Check an authorization request (query of GET /oauth/authorize, body of the consent POST).
     *
     * @param  array<string, mixed>  $params
     * @return array{client: McpOAuthClient, redirect_uri: string, state: ?string, code_challenge: string}
     *
     * @throws McpOAuthError
     */
    public function authorizationRequest(array $params): array
    {
        $param = fn (string $key): ?string => is_string($params[$key] ?? null) && $params[$key] !== '' ? $params[$key] : null;

        $client = $param('client_id') === null ? null : McpOAuthClient::query()->find($param('client_id'));
        if ($client === null || $client->revoked_at !== null) {
            throw new McpOAuthError('invalid_client', 'This app is not registered with Bridge.');
        }
        $redirectUri = $param('redirect_uri');
        if ($redirectUri === null || ! $client->hasRedirectUri($redirectUri)) {
            throw new McpOAuthError('invalid_request', 'The redirect URI is not registered for this app.');
        }
        $state = $param('state');
        $refuse = fn (string $error, string $description) => new McpOAuthError($error, $description, 400,
            self::redirectWith($redirectUri, ['error' => $error, 'error_description' => $description, 'state' => $state]));

        if ($param('response_type') !== 'code') {
            throw $refuse('unsupported_response_type', 'Only response_type=code is supported.');
        }
        $challenge = $param('code_challenge');
        if ($challenge === null || preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) !== 1) {
            throw $refuse('invalid_request', 'PKCE is required: send an S256 code_challenge.');
        }
        if ($param('code_challenge_method') !== 'S256') {
            throw $refuse('invalid_request', 'code_challenge_method must be S256.');
        }
        $resource = $param('resource');
        if ($resource !== null && ! in_array(rtrim($resource, '/'), [self::url(), self::resource()], true)) {
            throw $refuse('invalid_target', 'The resource must be '.self::resource().'.');
        }

        return ['client' => $client, 'redirect_uri' => $redirectUri, 'state' => $state, 'code_challenge' => $challenge];
    }

    /** The user approved: a one-time code bound to the client, user, workspace, redirect URI and PKCE challenge. */
    public function issueCode(McpOAuthClient $client, User $user, Workspace $workspace, string $redirectUri, string $codeChallenge): string
    {
        $code = Str::random(64);
        McpOAuthCode::query()->create([
            'code_hash' => hash('sha256', $code),
            'client_id' => $client->id,
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'expires_at' => Carbon::now()->addMinutes(self::CODE_MINUTES),
        ]);

        return $code;
    }

    /** @param  array<string, ?string>  $query */
    public static function redirectWith(string $redirectUri, array $query): string
    {
        $query = http_build_query(array_filter($query, fn (?string $value) => $value !== null));

        return $redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').$query;
    }

    /**
     * The client calling the token endpoint: client_secret_basic, client_secret_post, or none (public clients).
     *
     * @throws McpOAuthError invalid_client (401)
     */
    public function authenticateClient(Request $request): McpOAuthClient
    {
        $basic = $request->getUser() !== null;
        $id = $basic ? urldecode((string) $request->getUser()) : $request->input('client_id');
        $secret = $basic ? urldecode((string) $request->getPassword()) : $request->input('client_secret');

        $client = is_string($id) && $id !== '' ? McpOAuthClient::query()->find($id) : null;
        $known = $client !== null && $client->revoked_at === null;
        if ($known && $client->isConfidential()) {
            $known = is_string($secret) && hash_equals((string) $client->secret_hash, hash('sha256', $secret));
        }
        if (! $known) {
            throw new McpOAuthError('invalid_client', 'Unknown client or wrong client secret.', 401);
        }

        return $client;
    }

    /**
     * grant_type=authorization_code. The code works once: a second use also revokes the grant of the first.
     *
     * @return array<string, mixed> the token response
     *
     * @throws McpOAuthError invalid_grant
     */
    public function exchangeCode(McpOAuthClient $client, mixed $code, mixed $redirectUri, mixed $codeVerifier): array
    {
        $invalid = fn (string $why) => new McpOAuthError('invalid_grant', $why);
        $row = is_string($code) && $code !== '' ? McpOAuthCode::query()->where('code_hash', hash('sha256', $code))->first() : null;
        if ($row === null || $row->client_id !== $client->id) {
            throw $invalid('The authorization code is invalid.');
        }
        if (McpOAuthCode::query()->whereKey($row->id)->whereNull('used_at')->update(['used_at' => Carbon::now()]) === 0) {
            if ($row->personal_access_token_id !== null) {
                $this->revokeGrant((int) $row->personal_access_token_id);
            }
            throw $invalid('The authorization code was already used.');
        }
        if ($row->expires_at->isPast()) {
            throw $invalid('The authorization code has expired.');
        }
        if (! is_string($redirectUri) || ! hash_equals($row->redirect_uri, $redirectUri)) {
            throw $invalid('The redirect URI does not match the authorization request.');
        }
        if (! is_string($codeVerifier) || preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $codeVerifier) !== 1
            || ! hash_equals($row->code_challenge, self::challengeFor($codeVerifier))) {
            throw $invalid('The PKCE code verifier does not match.');
        }

        $user = User::query()->find($row->user_id);
        $workspace = Workspace::query()->find($row->workspace_id);
        if ($user === null || $workspace === null || $user->isBlocked()) {
            throw $invalid('The account that approved this request is not available.');
        }
        try {
            $grant = $this->current->runAs($workspace, fn () => $this->tokens->create($user, $workspace, self::grantName($client), 'write', null)['token']);
        } catch (AuthorizationException $e) {
            throw $invalid($e->getMessage());
        }
        $row->forceFill(['personal_access_token_id' => $grant->getKey()])->save();
        $this->prune();

        return $this->issueTokens($client, $user->id, (int) $grant->getKey());
    }

    /**
     * grant_type=refresh_token: the pair is replaced (rotation). Presenting an already rotated refresh token revokes
     * the whole grant, since either the client or a thief is replaying it.
     *
     * @return array<string, mixed> the token response
     *
     * @throws McpOAuthError invalid_grant
     */
    public function refresh(McpOAuthClient $client, mixed $refreshToken): array
    {
        $invalid = fn (string $why) => new McpOAuthError('invalid_grant', $why);
        $row = is_string($refreshToken) && $refreshToken !== ''
            ? McpOAuthToken::query()->where('refresh_token_hash', hash('sha256', $refreshToken))->first() : null;
        if ($row === null || $row->client_id !== $client->id) {
            throw $invalid('The refresh token is invalid.');
        }
        if (McpOAuthToken::query()->whereKey($row->id)->whereNull('revoked_at')->update(['revoked_at' => Carbon::now()]) === 0) {
            $this->revokeGrant((int) $row->personal_access_token_id);
            throw $invalid('The refresh token was already used; the connection was revoked.');
        }
        if ($row->refresh_expires_at->isPast()) {
            throw $invalid('The refresh token has expired.');
        }
        if (! PersonalAccessToken::query()->whereKey($row->personal_access_token_id)->exists()) {
            throw $invalid('This connection was revoked.');
        }

        return $this->issueTokens($client, (int) $row->user_id, (int) $row->personal_access_token_id);
    }

    /**
     * The user of an OAuth access token on /mcp, acting as the grant's API token row (so the workspace, abilities and
     * agent come from it exactly as for a Sanctum bearer); null for anything else.
     */
    public static function userForBearer(?string $bearer): ?User
    {
        if ($bearer === null || ! str_starts_with($bearer, self::ACCESS_PREFIX)) {
            return null;
        }
        $row = McpOAuthToken::query()->where('access_token_hash', hash('sha256', $bearer))->whereNull('revoked_at')->first();
        if ($row === null || $row->access_expires_at->isPast()
            || ! McpOAuthClient::query()->whereKey($row->client_id)->whereNull('revoked_at')->exists()) {
            return null;
        }
        $grant = PersonalAccessToken::query()->find($row->personal_access_token_id);
        $user = $grant?->tokenable;
        if (! $user instanceof User) {
            return null;
        }
        $grant->forceFill(['last_used_at' => Carbon::now()])->save();

        return $user->withAccessToken($grant);
    }

    /** Delete the grant's API token row and revoke its pairs; false when the row was already gone. */
    public function revokeGrant(int $personalAccessTokenId): bool
    {
        McpOAuthToken::query()->where('personal_access_token_id', $personalAccessTokenId)->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);

        return PersonalAccessToken::query()->whereKey($personalAccessTokenId)->delete() > 0;
    }

    /** The name of a grant's API token (and of its agent), as listed under Account → API tokens. */
    public static function grantName(McpOAuthClient $client): string
    {
        return Str::limit($client->name, 50, '').' (OAuth)';
    }

    public static function challengeFor(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function issueTokens(McpOAuthClient $client, int $userId, int $personalAccessTokenId): array
    {
        $access = self::ACCESS_PREFIX.Str::random(48);
        $refresh = self::REFRESH_PREFIX.Str::random(48);
        McpOAuthToken::query()->create([
            'client_id' => $client->id,
            'user_id' => $userId,
            'personal_access_token_id' => $personalAccessTokenId,
            'access_token_hash' => hash('sha256', $access),
            'access_expires_at' => Carbon::now()->addMinutes(self::ACCESS_MINUTES),
            'refresh_token_hash' => hash('sha256', $refresh),
            'refresh_expires_at' => Carbon::now()->addDays(self::REFRESH_DAYS),
        ]);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_MINUTES * 60,
            'refresh_token' => $refresh,
            'scope' => self::SCOPE,
        ];
    }

    /** Codes and pairs that can no longer be used (kept a day so a replay is still recognized). */
    private function prune(): void
    {
        McpOAuthCode::query()->where('expires_at', '<', Carbon::now()->subDay())->delete();
        McpOAuthToken::query()->where('refresh_expires_at', '<', Carbon::now()->subDay())->delete();
    }
}
