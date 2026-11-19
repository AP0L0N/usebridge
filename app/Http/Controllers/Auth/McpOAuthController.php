<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Pages\AuthPageController;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Services\Auth\McpOAuth;
use App\Services\Auth\McpOAuthError;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth 2.1 endpoints of the MCP server (docs/MCP-OAUTH.md): discovery metadata, the consent screen of the
 * authorization endpoint (web session; guests log in first and come back), the token endpoint and dynamic client
 * registration. The rules live in McpOAuth; errors are McpOAuthError.
 */
class McpOAuthController extends Controller
{
    /** Authorization request parameters the consent screen posts back unchanged. */
    private const REQUEST_PARAMS = ['client_id', 'redirect_uri', 'response_type', 'state', 'code_challenge', 'code_challenge_method', 'scope', 'resource'];

    public function __construct(private readonly McpOAuth $oauth) {}

    public function protectedResource(): JsonResponse
    {
        return response()->json(McpOAuth::protectedResourceMetadata());
    }

    public function authorizationServer(): JsonResponse
    {
        return response()->json(McpOAuth::authorizationServerMetadata());
    }

    /** GET /oauth/authorize: the consent screen, or the error straight back to the client when the request is bad. */
    public function consent(Request $request, CurrentWorkspace $current): Response
    {
        $page = fn (array $props) => Inertia::render('Auth/OAuthConsent', [
            'copy' => AuthPageController::copy(),
            'client' => null,
            'error' => null,
            ...$props,
        ])->toResponse($request);

        try {
            $authorization = $this->oauth->authorizationRequest($request->query());
        } catch (McpOAuthError $e) {
            if ($e->redirectTo !== null) {
                return redirect()->away($e->redirectTo);
            }

            return $page(['error' => $e->getMessage()])->setStatusCode(400);
        }

        $response = $page([
            'client' => [
                'name' => $authorization['client']->name,
                'dynamic' => $authorization['client']->is_dynamic,
                'redirectUri' => $authorization['redirect_uri'],
            ],
            'account' => ['email' => $request->user()->email, 'workspace' => $current->get()?->name],
            'emailVerified' => $request->user()->hasVerifiedEmail(),
            'params' => (object) array_filter($request->only(self::REQUEST_PARAMS), 'is_string'),
            'decideUrl' => route('mcp.oauth.decide', absolute: false),
        ]);
        // The consent screen is never framed (clickjacking).
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");

        return $response;
    }

    /** POST /oauth/authorize {decision: approve|deny, ...the request}: where the browser goes next (the client's redirect URI). */
    public function decide(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $request->validate(['decision' => ['required', 'in:approve,deny']]);
        try {
            $authorization = $this->oauth->authorizationRequest($request->only(self::REQUEST_PARAMS));
        } catch (McpOAuthError $e) {
            return $e->redirectTo !== null
                ? response()->json(['redirect' => $e->redirectTo])
                : response()->json(['message' => $e->getMessage()], 400);
        }
        ['client' => $client, 'redirect_uri' => $redirectUri, 'state' => $state] = $authorization;

        if ($request->input('decision') === 'deny') {
            return response()->json(['redirect' => McpOAuth::redirectWith($redirectUri, [
                'error' => 'access_denied', 'error_description' => 'The user denied the request.', 'state' => $state,
            ])]);
        }
        if (! $request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => RequireVerifiedEmail::MESSAGE, 'verified' => false], 403);
        }
        $code = $this->oauth->issueCode($client, $request->user(), $current->get(), $redirectUri, $authorization['code_challenge']);

        return response()->json(['redirect' => McpOAuth::redirectWith($redirectUri, ['code' => $code, 'state' => $state])]);
    }

    /** POST /oauth/token: authorization_code (PKCE) and refresh_token grants. */
    public function token(Request $request): JsonResponse
    {
        $client = $this->oauth->authenticateClient($request);
        $tokens = match ($request->input('grant_type')) {
            'authorization_code' => $this->oauth->exchangeCode($client, $request->input('code'), $request->input('redirect_uri'), $request->input('code_verifier')),
            'refresh_token' => $this->oauth->refresh($client, $request->input('refresh_token')),
            default => throw new McpOAuthError('unsupported_grant_type', 'Use grant_type authorization_code or refresh_token.'),
        };

        return response()->json($tokens)->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    /** POST /oauth/register (RFC 7591): public clients only (no secret; PKCE is always required). */
    public function register(Request $request): JsonResponse
    {
        abort_unless(McpOAuth::dynamicRegistrationEnabled(), 404);
        $validator = Validator::make($request->all(), [
            'redirect_uris' => ['required', 'array', 'min:1', 'max:10'],
            'redirect_uris.*' => ['required', 'string'],
            'client_name' => ['nullable', 'string', 'max:100'],
            'token_endpoint_auth_method' => ['nullable', 'in:none'],
            'grant_types' => ['nullable', 'array'],
            'grant_types.*' => ['in:authorization_code,refresh_token'],
            'response_types' => ['nullable', 'array'],
            'response_types.*' => ['in:code'],
        ]);
        if ($validator->fails()) {
            $redirects = $validator->errors()->has('redirect_uris') || $validator->errors()->has('redirect_uris.*');
            throw new McpOAuthError($redirects ? 'invalid_redirect_uri' : 'invalid_client_metadata', $validator->errors()->first());
        }
        $redirectUris = array_values($request->input('redirect_uris'));
        foreach ($redirectUris as $uri) {
            if (! McpOAuth::validRedirectUri($uri)) {
                throw new McpOAuthError('invalid_redirect_uri', 'Redirect URIs must be https, http on localhost, or an app scheme, without a fragment.');
            }
        }
        $client = $this->oauth->createClient((string) ($request->input('client_name') ?: 'MCP client'), $redirectUris, confidential: false, dynamic: true)['client'];

        return response()->json([
            'client_id' => $client->id,
            'client_id_issued_at' => $client->created_at->getTimestamp(),
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => McpOAuth::SCOPE,
        ], 201)->header('Cache-Control', 'no-store');
    }
}
