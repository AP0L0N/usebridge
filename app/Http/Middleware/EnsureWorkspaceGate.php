<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * AUTH-1: shared workspace password gate (session). No per-agent ACL.
 */
class EnsureWorkspaceGate
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('bridge_gate_unlocked') === true) {
            return $next($request);
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location(route('gate.show'));
        }

        return redirect()->guest(route('gate.show'));
    }
}
