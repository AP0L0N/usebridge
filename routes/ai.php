<?php

use App\Http\Controllers\Auth\McpOAuthController;
use App\Http\Middleware\McpOAuthChallenge;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Http\Middleware\ResolveTokenWorkspace;
use App\Http\Middleware\WorkspaceBoundary;
use App\Mcp\Servers\BridgeServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

// MCP (docs/MULTI-USER-PLAN.md §6), loaded by laravel/mcp: POST /mcp, streamable HTTP (GET/DELETE 405). Same token
// checks as /api: a per-user Bearer token, the token's workspace (disabled / deletion-pending users 403), plus a
// verified email (403) and 120 requests/min per token (429). Abilities are checked per tool (BridgeTool).
// The bearer is a Sanctum API token or an OAuth access token (guard mcp-oauth, docs/MCP-OAUTH.md), which acts as the
// API token row of its grant. A 401 carries the OAuth discovery challenge (McpOAuthChallenge).
Mcp::web('/mcp', BridgeServer::class)
    ->middleware([WorkspaceBoundary::class, McpOAuthChallenge::class, 'auth:sanctum,mcp-oauth', ResolveTokenWorkspace::class, RequireVerifiedEmail::class, 'throttle:mcp'])
    ->withoutMiddleware(AddWwwAuthenticateHeader::class)
    ->name('mcp');

// OAuth 2.1 for /mcp (docs/MCP-OAUTH.md): discovery (RFC 9728 / RFC 8414, with the /mcp path variants), the token
// endpoint and dynamic client registration. Stateless JSON; the consent screen (/oauth/authorize) is in routes/web.php.
Route::get('/.well-known/oauth-protected-resource', [McpOAuthController::class, 'protectedResource'])->name('mcp.oauth.protected-resource');
Route::get('/.well-known/oauth-protected-resource/mcp', [McpOAuthController::class, 'protectedResource']);
Route::get('/.well-known/oauth-authorization-server', [McpOAuthController::class, 'authorizationServer'])->name('mcp.oauth.authorization-server');
Route::get('/.well-known/oauth-authorization-server/mcp', [McpOAuthController::class, 'authorizationServer']);
Route::post('/oauth/token', [McpOAuthController::class, 'token'])->middleware('throttle:60,1')->name('mcp.oauth.token');
Route::post('/oauth/register', [McpOAuthController::class, 'register'])->middleware('throttle:10,1')->name('mcp.oauth.register');
