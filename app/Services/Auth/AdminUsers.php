<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Owner admin (relayed decision 2, docs/MULTI-USER-PLAN.md §5): the user list and disable / enable. Nothing else:
 * no counts, projects, boards, tickets, statuses or usage of other workspaces, no delete, no impersonation. Only the
 * users table (and their sessions rows) is touched; no workspace-owned model is ever queried here.
 */
final class AdminUsers
{
    /** Exactly these keys leave this service. */
    public const KEYS = ['id', 'name', 'email', 'created_at', 'last_login_at', 'status'];

    public function __construct(private readonly DeviceSessions $sessions) {}

    /** @return list<array{id: int, name: string, email: string, created_at: ?string, last_login_at: ?string, status: string}> */
    public function list(): array
    {
        return User::query()->orderBy('id')
            ->get(['id', 'name', 'email', 'created_at', 'last_login_at', 'disabled_at', 'delete_requested_at', 'deleted_at'])
            ->map(fn (User $u) => self::row($u))->values()->all();
    }

    /**
     * disabled_at = now, every session deleted. Tokens stay but are blocked (ResolveTokenWorkspace answers 403), so
     * enable() restores the user's bots unchanged.
     *
     * @throws ValidationException on yourself
     */
    public function disable(User $admin, User $user): User
    {
        $this->notSelf($admin, $user);
        if ($user->disabled_at === null) {
            $user->forceFill(['disabled_at' => Carbon::now()])->saveQuietly();
        }
        $this->sessions->logoutAll($user);

        return $user;
    }

    /** @throws ValidationException on yourself */
    public function enable(User $admin, User $user): User
    {
        $this->notSelf($admin, $user);
        $user->forceFill(['disabled_at' => null])->saveQuietly();

        return $user;
    }

    /** One row of list() for $user. */
    public static function row(User $u): array
    {
        return array_combine(self::KEYS, [
            $u->id, $u->name, $u->email, $u->created_at?->toIso8601String(), $u->last_login_at?->toIso8601String(), $u->status(),
        ]);
    }

    private function notSelf(User $admin, User $user): void
    {
        if ($admin->is($user)) {
            throw ValidationException::withMessages(['user' => 'You cannot disable or enable your own account.']);
        }
    }
}
