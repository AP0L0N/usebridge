<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Account deletion with a 7-day grace (docs/MULTI-USER-PLAN.md §2): request (typed email confirm) sets
 * delete_requested_at, ends every session and blocks the tokens (ResolveTokenWorkspace, like disabled; not revoked).
 * Within the grace the user can cancel from the login screen. After it, `bridge:accounts-delete-expired` soft-deletes
 * the user (deleted_at). Nothing is purged: workspace rows stay until Tilen explicitly OKs a purge.
 */
final class AccountDeletion
{
    public const GRACE_DAYS = 7;

    public function __construct(private readonly DeviceSessions $sessions) {}

    public static function purgeAt(User $user): Carbon
    {
        return $user->delete_requested_at->copy()->addDays(self::GRACE_DAYS);
    }

    /** @throws ValidationException when the typed email does not match */
    public function request(User $user, string $confirmEmail): void
    {
        if (Str::lower(trim($confirmEmail)) !== Str::lower($user->email)) {
            throw ValidationException::withMessages(['email' => 'Type your account email to confirm.']);
        }
        $user->forceFill(['delete_requested_at' => Carbon::now()])->saveQuietly();
        $this->sessions->logoutAll($user);
    }

    /** Withdraws a pending request inside the grace. False when there is none (or the grace is over). */
    public function cancel(User $user): bool
    {
        if ($user->delete_requested_at === null || self::purgeAt($user)->isPast()) {
            return false;
        }
        $user->forceFill(['delete_requested_at' => null])->saveQuietly();

        return true;
    }

    /** Soft-deletes users whose grace is over. Returns their ids. */
    public function deleteExpired(bool $dryRun = false): array
    {
        $due = User::query()->whereNotNull('delete_requested_at')
            ->where('delete_requested_at', '<=', Carbon::now()->subDays(self::GRACE_DAYS))
            ->orderBy('id')->get();
        if (! $dryRun) {
            foreach ($due as $user) {
                $this->sessions->logoutAll($user);
                $user->delete(); // SoftDeletes: sets deleted_at
            }
        }

        return $due->pluck('id')->all();
    }
}
