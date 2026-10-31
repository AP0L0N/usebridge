<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Workspaces\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web pages: a logged-in user acts in their own workspace (owned, else their only membership; 403 without one).
 * Otherwise AUTH-1: shared workspace password gate (session), acting in Tilen's workspace (bridge.default_workspace)
 * until the login screens replace the gate (docs/MULTI-USER-PLAN.md §2). A login wins over an unlocked gate. Being
 * is_admin changes nothing here: an admin sees only their own workspace (§5).
 */
class EnsureWorkspaceGate
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if ($user instanceof User) {
            $workspace = $user->homeWorkspace();
            abort_if($workspace === null, 403, 'This account has no workspace.');
            $this->current->set($workspace);

            return $next($request);
        }

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
