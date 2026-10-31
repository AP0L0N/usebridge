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
 * Web pages: only a logged-in user gets in, and acts in their own workspace (owned, else their only membership; 403
 * without one). Guests go to the login screen. Being is_admin changes nothing here: an admin sees only their own
 * workspace (docs/MULTI-USER-PLAN.md §5). /api and /mcp resolve the workspace from the token instead.
 */
class EnsureUserWorkspace
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

        if ($request->header('X-Inertia')) {
            return Inertia::location(route('login'));
        }

        return redirect()->guest(route('login'));
    }
}
