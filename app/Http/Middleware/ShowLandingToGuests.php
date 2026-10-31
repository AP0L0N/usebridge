<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/` for a guest (no web login) renders the public landing page instead of redirecting to /login. A logged-in user
 * falls through to EnsureUserWorkspace and the Portfolio. Inertia visits (X-Inertia) fall through as well, so a client
 * navigation that lost its session still gets the login location.
 */
class ShowLandingToGuests
{
    public function handle(Request $request, Closure $next): Response
    {
        $isGuest = Auth::guard('web')->guest();

        if ($isGuest && ! $request->header('X-Inertia')) {
            return response()->view('landing');
        }

        return $next($request);
    }
}
