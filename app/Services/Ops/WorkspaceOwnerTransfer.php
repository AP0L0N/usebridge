<?php

namespace App\Services\Ops;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * `bridge:set-owner` (docs/MULTI-USER-PLAN.md §11): make the user with this email the owner of a workspace, e.g. Tilen
 * on a copy migrated before BRIDGE_OWNER_EMAIL was required (Captain's API user ended up owner + admin there).
 *
 * - The user is found by email (lower-cased); soft-deleted, disabled or deletion-pending users are refused. A missing
 *   user is created with the given name (else the email's local part), an unusable random password (nobody knows it;
 *   the owner sets one via the password reset flow) and is_admin. email_verified_at is set (also on an existing,
 *   unverified user): the owner email is set by ops, not typed into a sign-up form, so it counts as verified.
 * - workspaces.owner_user_id := the user, membership role 'owner' (inserted or updated). Every other owner of that
 *   workspace (role 'owner' or owner_user_id) becomes 'member' and loses is_admin unless it still owns another
 *   workspace. Their tokens (hash, abilities, workspace_id) and agent links are not touched.
 * Idempotent and transactional; nothing is deleted. $dryRun computes the same plan and writes nothing.
 */
final class WorkspaceOwnerTransfer
{
    /**
     * @return array{workspace: Workspace, user_id: ?int, created: bool, demoted: list<int>}
     *
     * @throws InvalidArgumentException unknown workspace, invalid email, refused user
     */
    public function apply(string $email, ?string $name, string $slug, bool $dryRun): array
    {
        $email = Str::lower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('--email is not a valid email address.');
        }
        $workspace = Workspace::query()->where('slug', $slug)->first();
        if ($workspace === null) {
            throw new InvalidArgumentException("Workspace '$slug' not found.");
        }

        return DB::transaction(function () use ($email, $name, $workspace, $dryRun) {
            $user = User::withTrashed()->where('email', $email)->lockForUpdate()->first();
            if ($user !== null && $user->isBlocked()) {
                throw new InvalidArgumentException("User #{$user->id} is soft-deleted, disabled or pending deletion; refusing.");
            }
            $created = $user === null;

            $previous = $workspace->users()->withTrashed()->wherePivot('role', 'owner')->pluck('users.id')
                ->push($workspace->owner_user_id)->filter()->map(fn ($id) => (int) $id)
                ->reject(fn (int $id) => $id === $user?->id)->unique()->sort()->values()->all();

            if ($dryRun) {
                return ['workspace' => $workspace, 'user_id' => $user?->id, 'created' => $created, 'demoted' => $previous];
            }

            $user ??= new User;
            $user->forceFill([
                ...($created ? [
                    'name' => filled($name) ? trim((string) $name) : Str::before($email, '@'),
                    'email' => $email,
                    'password' => Hash::make(Str::random(64)),
                ] : []),
                'email_verified_at' => $user->email_verified_at ?? Carbon::now(),
                'is_admin' => true,
            ])->save();

            $this->setRole($workspace, $user->id, 'owner');
            $workspace->forceFill(['owner_user_id' => $user->id])->save();

            foreach ($previous as $id) {
                $this->setRole($workspace, $id, 'member');
                if (! Workspace::query()->where('owner_user_id', $id)->exists()) {
                    User::withTrashed()->whereKey($id)->first()?->forceFill(['is_admin' => false])->save();
                }
            }

            return ['workspace' => $workspace, 'user_id' => $user->id, 'created' => $created, 'demoted' => $previous];
        });
    }

    private function setRole(Workspace $workspace, int $userId, string $role): void
    {
        $current = $workspace->users()->withTrashed()->whereKey($userId)->first()?->pivot->role;
        if ($current === $role) {
            return;
        }
        if ($current !== null) {
            $workspace->users()->updateExistingPivot($userId, ['role' => $role]);
        } else {
            $workspace->users()->attach($userId, ['role' => $role]);
        }
    }
}
