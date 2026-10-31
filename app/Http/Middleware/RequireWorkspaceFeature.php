<?php

namespace App\Http\Middleware;

use App\Support\Workspaces\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `feature:polymarket`-style switch per workspace (workspaces.features): 404 when the current workspace does not have
 * it, so the feature stays invisible. Runs after the gate / token middleware resolved the workspace.
 */
class RequireWorkspaceFeature
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless($this->current->hasFeature($feature), 404);

        return $next($request);
    }
}
