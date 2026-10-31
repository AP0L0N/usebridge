<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/` for a pure guest (no web login, no unlocked AUTH-1 gate) renders the public landing page instead of redirecting
 * to /login. Everyone who reaches the app at `/` today (a logged-in user, an unlocked gate session) falls through
 * to EnsureWorkspaceGate and the Portfolio unchanged. Inertia visits (X-Inertia) fall through as well, so a client
 * navigation that lost its session still gets the login location.
 */
class ShowLandingToGuests
{
    public function handle(Request $request, Closure $next): Response
    {
        $isGuest = Auth::guard('web')->guest() && $request->session()->get('bridge_gate_unlocked') !== true;

        if ($isGuest && ! $request->header('X-Inertia')) {
            return response()->view('landing');
        }

        return $next($request);
    }
}
