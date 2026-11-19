<?php

namespace App\Http\Middleware;

use App\Services\Auth\McpOAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /mcp, before auth: a 401 tells the client where to start OAuth (docs/MCP-OAUTH.md), with the protected resource
 * metadata URL on APP_URL. Replaces laravel/mcp's AddWwwAuthenticateHeader on this route (it would use the request host).
 */
class McpOAuthChallenge
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response->getStatusCode() === 401) {
            $response->headers->set('WWW-Authenticate', McpOAuth::challenge($request->bearerToken() !== null));
        }

        return $response;
    }
}
