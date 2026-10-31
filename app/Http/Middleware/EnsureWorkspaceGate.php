<?php

namespace App\Http\Middleware;

use App\Support\Workspaces\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * AUTH-1: shared workspace password gate (session). No per-agent ACL.
 * Multi-user transition: an unlocked gate session acts in Tilen's workspace (bridge.default_workspace) until the login
 * screens replace the gate (docs/MULTI-USER-PLAN.md §2).
 */
class EnsureWorkspaceGate
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('bridge_gate_unlocked') === true) {
            $workspace = $this->current->default();
            abort_if($workspace === null, 503, 'Workspace not configured (run the migrations).');
            $this->current->set($workspace);

            return $next($request);
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location(route('gate.show'));
        }

        return redirect()->guest(route('gate.show'));
    }
}
