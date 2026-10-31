<?php

namespace App\Services\Auth;

use App\Models\Agent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Go-live B3: every API token writes as an agent of its own workspace. When a request names no `actor`, the token's
 * agent (personal_access_tokens.agent_id) is the actor. It is created with the token (name = token name) or, for older
 * tokens, on their first write. agents.id is a global primary key, so the id is the slugified token name plus a short
 * random suffix (agent-<slug>-<6 chars>), unique across every workspace; the row belongs to the token's workspace.
 */
final class TokenAgents
{
    public const ROLE_LABEL = 'Bot';

    /**
     * The token's agent in the token's workspace, created (and remembered on the token) when missing. An unbound legacy
     * token (no workspace_id) uses $fallbackWorkspaceId, the workspace ResolveTokenWorkspace picked for this request.
     */
    public static function provision(PersonalAccessToken $token, ?int $fallbackWorkspaceId = null): ?Agent
    {
        $workspaceId = $token->getAttribute('workspace_id') ?? $fallbackWorkspaceId;
        if ($workspaceId === null) {
            return null;
        }

        return DB::transaction(function () use ($token, $workspaceId) {
            $token->refresh();
            $current = $token->getAttribute('agent_id');
            if ($current !== null) {
                $agent = Agent::acrossWorkspaces()->whereKey($current)->where('workspace_id', $workspaceId)->first();
                if ($agent !== null) {
                    return $agent;
                }
            }
            $name = trim((string) $token->name) !== '' ? trim((string) $token->name) : 'API bot';
            $agent = new Agent(['id' => self::newId($name), 'name' => Str::limit($name, 255, ''), 'role_label' => self::ROLE_LABEL]);
            $agent->setAttribute('workspace_id', (int) $workspaceId);
            $agent->save();
            $token->forceFill(['agent_id' => $agent->id])->save();

            return $agent;
        });
    }

    /** agent-<slug of name, max 40>-<6 random [a-z0-9]>, matching AgentController's id pattern; unique globally. */
    public static function newId(string $name): string
    {
        $slug = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->limit(40, '')->trim('-')->value();
        $slug = preg_replace('/^agent-?/', '', $slug) ?: 'bot';
        do {
            $id = 'agent-'.$slug.'-'.Str::lower(Str::random(6));
            $id = preg_replace('/[^a-z0-9-]/', 'x', $id);
        } while (Agent::acrossWorkspaces()->whereKey($id)->exists());

        return $id;
    }
}
