<?php

namespace App\Http\Middleware;

use App\Support\Workspaces\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First middleware of the web and api groups: every HTTP request must resolve a workspace (login or token) before it
 * touches owned data, otherwise the scope throws (fail closed). The previous state is restored afterwards, so one
 * request never leaks its workspace into the next (or into test code after a request).
 */
class WorkspaceBoundary
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $state = $this->current->snapshot();
        $this->current->set(null);
        $this->current->require();
        try {
            return $next($request);
        } finally {
            $this->current->restore($state);
        }
    }
}
