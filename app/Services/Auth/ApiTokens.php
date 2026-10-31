<?php

namespace App\Services\Auth;

use App\Http\Middleware\EnsureTokenAbility;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A user's own API tokens (docs/MULTI-USER-PLAN.md §4). Sanctum, sha256 at rest; the plaintext is returned once.
 *
 * - Scope -> abilities: read = boards:read; write = boards:read/write, status:write, agents:write, plus usage:write /
 *   polymarket:write only when the workspace has that feature (polymarket:write also only for the workspace owner; the
 *   /api/polymarket routes check the owner again on every request). The UI never issues '*' / 'ceo'.
 * - Expiry 30 / 90 / 365 days or none -> expires_at (Sanctum rejects expired tokens).
 * - New tokens carry sanctum.token_prefix (brg_live_) and keep the last 4 plaintext characters in token_suffix for the
 *   masked list; the token is bound to the workspace it was created in. Legacy tokens keep working, listed as legacy.
 * - Each new token gets its own agent in the workspace (TokenAgents, name = token name): API / MCP writes without an
 *   `actor` are recorded as that agent. Revoking keeps the agent (agents are never deleted).
 * - Revoke deletes the token row (a credential, not data), own tokens only. Creating needs a verified email.
 */
final class ApiTokens
{
    public const SCOPES = ['read', 'write'];

    public const EXPIRY_DAYS = [30, 90, 365];

    /** @return list<string> */
    public static function abilitiesFor(string $scope, Workspace $workspace, ?User $user = null): array
    {
        if ($scope === 'read') {
            return ['boards:read'];
        }

        return [
            'boards:read', 'boards:write', 'status:write', 'agents:write',
            ...($workspace->hasFeature(Workspace::FEATURE_USAGE_UPLOAD) ? ['usage:write'] : []),
            ...($workspace->hasFeature(Workspace::FEATURE_POLYMARKET) && $user !== null && $workspace->isOwnedBy($user) ? ['polymarket:write'] : []),
        ];
    }

    /** Scope shown in the list, derived from the abilities: read, write, full (legacy '*' / 'ceo') or custom. */
    public static function scopeOf(array $abilities): string
    {
        return match (true) {
            array_intersect($abilities, EnsureTokenAbility::FULL) !== [] => 'full',
            $abilities === ['boards:read'] => 'read',
            in_array('boards:write', $abilities, true) && in_array('boards:read', $abilities, true) => 'write',
            default => 'custom',
        };
    }

    /**
     * @return array{token: PersonalAccessToken, plain_text: string}
     *
     * @throws AuthorizationException unverified email
     * @throws ValidationException
     */
    public function create(User $user, Workspace $workspace, string $name, string $scope, ?int $expiresInDays): array
    {
        if (! $user->hasVerifiedEmail()) {
            throw new AuthorizationException(RequireVerifiedEmail::MESSAGE);
        }
        if (! $workspace->hasMember($user)) {
            throw new AuthorizationException('Not a member of this workspace.');
        }
        Validator::make(['name' => trim($name), 'scope' => $scope, 'expires' => $expiresInDays], [
            'name' => ['required', 'string', 'max:60'],
            'scope' => ['required', Rule::in(self::SCOPES)],
            'expires' => ['nullable', 'integer', Rule::in(self::EXPIRY_DAYS)],
        ])->validate();

        $new = $user->createToken(trim($name), self::abilitiesFor($scope, $workspace, $user),
            $expiresInDays === null ? null : Carbon::now()->addDays($expiresInDays));
        // Shown once: the part after "id|" (Sanctum finds a token without the id by its hash).
        $plain = Str::after($new->plainTextToken, '|');
        $new->accessToken->forceFill(['workspace_id' => $workspace->id, 'token_suffix' => substr($plain, -4)])->save();
        // Go-live B3: the token's own agent (name = token name) is the actor when a write names none.
        // Read tokens cannot write, so they get none.
        if ($scope === 'write') {
            TokenAgents::provision($new->accessToken);
        }

        return ['token' => $new->accessToken, 'plain_text' => $plain];
    }

    /** @return list<array<string, mixed>> the user's own tokens, masked (never the hash) */
    public function list(User $user): array
    {
        return $user->tokens()->orderByDesc('id')->get()
            ->map(fn (PersonalAccessToken $t) => self::present($t))->values()->all();
    }

    public static function present(PersonalAccessToken $t): array
    {
        $suffix = $t->getAttribute('token_suffix');

        return [
            'id' => $t->id,
            'name' => $t->name,
            'suffix' => $suffix,
            'masked' => $suffix === null ? null : config('sanctum.token_prefix').'••••'.$suffix,
            'legacy' => $suffix === null,
            'scope' => self::scopeOf($t->abilities ?? []),
            'abilities' => $t->abilities ?? [],
            'created_at' => $t->created_at?->toIso8601String(),
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
            'agent_id' => $t->getAttribute('agent_id'),
        ];
    }

    /** Own tokens only; false when the id is not one of the user's tokens. */
    public function revoke(User $user, int $id): bool
    {
        return $user->tokens()->whereKey($id)->delete() > 0;
    }
}
