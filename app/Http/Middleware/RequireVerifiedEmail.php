<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verified email required (docs/MULTI-USER-PLAN.md §2): token creation (web) and, when it lands, /mcp (token user).
 * Always JSON 403, unlike the framework's `verified` (which redirects pages). Boards do not need it.
 */
class RequireVerifiedEmail
{
    public const MESSAGE = 'Verify your email address first.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof MustVerifyEmail || ! $user->hasVerifiedEmail()) {
            return response()->json(['message' => self::MESSAGE, 'verified' => false], 403);
        }

        return $next($request);
    }
}
