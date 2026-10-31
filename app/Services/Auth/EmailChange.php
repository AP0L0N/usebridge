<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\EmailChangeConfirmed;
use App\Notifications\EmailChangeRequested;
use App\Notifications\VerifyNewEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Email change with re-verification (docs/MULTI-USER-PLAN.md §8 account profile). A new address is only stored as
 * pending_email (+ pending_email_at); the login email stays the old one until the signed link sent to the new address
 * is opened. The link is bound to the user id and a hash of the pending address and its request time, so a link for an
 * older (cancelled or replaced) pending address fails. The old address gets a notice (new address masked) and, after
 * the swap, a confirmation. The swap ends every other session. The link itself never leaves the mail.
 */
final class EmailChange
{
    public const LINK_MINUTES = 60;

    public function __construct(private readonly DeviceSessions $sessions) {}

    /** sha256 of the pending address + its request time: what the signed link carries. */
    public static function hashFor(User $user): string
    {
        return hash('sha256', Str::lower((string) $user->pending_email).'|'.$user->pending_email_at?->getTimestamp());
    }

    /** "s••••@example.com" for the notice to the old address. */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return Str::substr($local, 0, 1).'••••@'.$domain;
    }

    public static function taken(string $email, User $except): bool
    {
        return User::withTrashed()->where('id', '!=', $except->id)->whereRaw('lower(email) = ?', [Str::lower($email)])->exists();
    }

    /**
     * Starts (or replaces) a pending change. Same address as the login email = nothing to do; same as the pending one =
     * nothing new (use resend).
     *
     * @throws ValidationException wrong current password or an address another account uses
     */
    public function request(User $user, string $email, ?string $currentPassword): bool
    {
        $email = Str::lower(trim($email));
        if ($email === Str::lower($user->email) || $email === Str::lower((string) $user->pending_email)) {
            return false;
        }
        if ($currentPassword === null || $currentPassword === '' || ! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('account.email.password_wrong')]);
        }
        if (self::taken($email, $user)) {
            throw ValidationException::withMessages(['email' => __('account.email.taken')]);
        }
        $user->forceFill(['pending_email' => $email, 'pending_email_at' => Carbon::now()])->save();
        $this->sendLink($user);
        $user->notify(new EmailChangeRequested(self::mask($email)));

        return true;
    }

    /** New link to the pending address (same binding, fresh 60 minutes). False when nothing is pending. */
    public function resend(User $user): bool
    {
        if ($user->pending_email === null) {
            return false;
        }
        $this->sendLink($user);

        return true;
    }

    public function cancel(User $user): bool
    {
        if ($user->pending_email === null) {
            return false;
        }
        $user->forceFill(['pending_email' => null, 'pending_email_at' => null])->save();

        return true;
    }

    /**
     * Opens the link: swaps the email, marks it verified, clears pending, mails the old address and ends every other
     * session ($keepSessionId stays when the same user opened it while logged in). Returns 'ok', 'invalid' (no pending,
     * stale hash) or 'taken' (another account took the address meanwhile; pending is cleared).
     */
    public function confirm(User $user, string $hash, ?string $keepSessionId = null): string
    {
        if ($user->pending_email === null || ! hash_equals(self::hashFor($user), $hash)) {
            return 'invalid';
        }
        $new = $user->pending_email;
        $old = $user->email;
        if (self::taken($new, $user)) {
            $user->forceFill(['pending_email' => null, 'pending_email_at' => null])->save();

            return 'taken';
        }
        DB::transaction(fn () => $user->forceFill([
            'email' => $new, 'email_verified_at' => Carbon::now(), 'pending_email' => null, 'pending_email_at' => null,
        ])->save());
        if ($keepSessionId === null) {
            $this->sessions->logoutAll($user);
        } else {
            $this->sessions->rotateRememberToken($user);
            $this->sessions->logoutOthers($user, $keepSessionId);
        }
        Notification::route('mail', $old)->notify(new EmailChangeConfirmed(self::mask($new)));

        return 'ok';
    }

    private function sendLink(User $user): void
    {
        Notification::route('mail', $user->pending_email)->notify(new VerifyNewEmail($user->id, self::hashFor($user)));
    }
}
