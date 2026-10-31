<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api (and later /mcp), after auth:sanctum: the workspace the token acts in. The token's workspace_id (the token user
 * must still be its owner or a member), else the user's owned workspace, else the user's only membership; 403
 * otherwise. The backfill bound the existing 'ceo' and per-agent tokens to Tilen's workspace.
 */
class ResolveTokenWorkspace
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workspace = $user instanceof User ? self::resolve($user, $user->currentAccessToken()) : null;
        if ($workspace === null) {
            return response()->json(['message' => 'This token is not bound to a workspace.'], 403);
        }
        $this->current->set($workspace);

        return $next($request);
    }

    public static function resolve(User $user, mixed $token): ?Workspace
    {
        $boundId = $token instanceof PersonalAccessToken && $token->exists ? $token->getAttribute('workspace_id') : null;
        if ($boundId !== null) {
            $workspace = Workspace::query()->find($boundId);

            return $workspace !== null && $workspace->hasMember($user) ? $workspace : null;
        }

        $owned = $user->ownedWorkspaces()->orderBy('id')->first();
        if ($owned !== null) {
            return $owned;
        }
        $memberships = $user->workspaces()->limit(2)->get();

        return $memberships->count() === 1 ? $memberships->first() : null;
    }
}
