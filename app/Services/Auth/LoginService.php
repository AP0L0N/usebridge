<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Web login (docs/MULTI-USER-PLAN.md §2):
 * - 10 failed attempts per minute per IP, then 429.
 * - Per account: a wrong password counts users.failed_logins; the 5th locks the account for 15 minutes
 *   (locked_until, counter reset). While locked even the right password is refused. A password reset clears the lock.
 * - Unknown emails get the identical answers (tries_left, then locked), counted in the RateLimiter by email, so the
 *   response never tells whether an account exists.
 * - Right password, then: disabled -> 403, deletion pending -> 403 with `deletion_pending` (cancel via
 *   cancelDeletionAndLogin within the grace). Otherwise login (+ remember), session regenerated, last_login_at.
 */
final class LoginService
{
    public const MAX_FAILS = 5;

    public const LOCK_MINUTES = 15;

    public const IP_PER_MINUTE = 10;

    private static ?string $dummyHash = null;

    /** @throws LoginRefused */
    public function login(string $email, string $password, bool $remember, ?string $ip, Session $session): User
    {
        $user = $this->check($email, $password, $ip);
        if ($user->disabled_at !== null) {
            throw new LoginRefused('This account is disabled.', 403, ['disabled' => true]);
        }
        if ($user->delete_requested_at !== null) {
            throw new LoginRefused('This account is scheduled for deletion. You can cancel the deletion until '
                .AccountDeletion::purgeAt($user)->toIso8601String().'.', 403, [
                    'deletion_pending' => true,
                    'delete_requested_at' => $user->delete_requested_at->toIso8601String(),
                    'purge_at' => AccountDeletion::purgeAt($user)->toIso8601String(),
                ]);
        }

        return $this->start($user, $remember, $session);
    }

    /**
     * "Cancel deletion" from the login screen: same credential check (and lockout), then the deletion request is
     * withdrawn and the user logged in. Disabled accounts stay refused.
     *
     * @throws LoginRefused
     */
    public function cancelDeletionAndLogin(string $email, string $password, ?string $ip, Session $session, AccountDeletion $deletion): User
    {
        $user = $this->check($email, $password, $ip);
        if ($user->disabled_at !== null) {
            throw new LoginRefused('This account is disabled.', 403, ['disabled' => true]);
        }
        if (! $deletion->cancel($user)) {
            throw new LoginRefused('There is no deletion to cancel for this account.', 422);
        }

        return $this->start($user->fresh(), false, $session);
    }

    public function logout(Session $session): void
    {
        Auth::guard('web')->logout();
        $session->invalidate();
        $session->regenerateToken();
    }

    /** Credentials + lockout only. @throws LoginRefused */
    public function check(string $email, string $password, ?string $ip): User
    {
        $email = Str::lower(trim($email));
        $ipKey = 'login-ip:'.($ip ?? 'none');
        if (RateLimiter::tooManyAttempts($ipKey, self::IP_PER_MINUTE)) {
            throw new LoginRefused('Too many login attempts. Try again in a minute.', 429, [
                'retry_after' => RateLimiter::availableIn($ipKey),
            ]);
        }

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->failUnknown($email, $password, $ipKey);
        }

        if ($user->locked_until !== null && $user->locked_until->isFuture()) {
            throw $this->locked($user->locked_until);
        }

        if (! Hash::check($password, $user->password)) {
            RateLimiter::hit($ipKey, 60);
            $fails = $user->failed_logins + 1;
            if ($fails >= self::MAX_FAILS) {
                $until = Carbon::now()->addMinutes(self::LOCK_MINUTES);
                $user->forceFill(['failed_logins' => 0, 'locked_until' => $until])->saveQuietly();
                throw $this->locked($until);
            }
            $user->forceFill(['failed_logins' => $fails])->saveQuietly();
            throw $this->wrong(self::MAX_FAILS - $fails);
        }

        return $user;
    }

    /** Clears the lock (successful login or password reset). */
    public static function clearLock(User $user): void
    {
        $user->forceFill(['failed_logins' => 0, 'locked_until' => null]);
    }

    private function start(User $user, bool $remember, Session $session): User
    {
        self::clearLock($user);
        $user->forceFill(['last_login_at' => Carbon::now()])->saveQuietly();
        Auth::guard('web')->login($user, $remember);
        $session->regenerate();

        return $user;
    }

    /** Unknown email: the same answers as a known one, counted by email in the RateLimiter. @throws LoginRefused */
    private function failUnknown(string $email, string $password, string $ipKey): never
    {
        Hash::check($password, self::$dummyHash ??= Hash::make(Str::random(32))); // same work as a known email
        $key = self::unknownKey($email);
        if (RateLimiter::attempts("$key:lock") > 0) {
            throw $this->locked(Carbon::now()->addSeconds(RateLimiter::availableIn("$key:lock")));
        }
        RateLimiter::hit($ipKey, 60);
        $fails = RateLimiter::hit($key, self::LOCK_MINUTES * 60);
        if ($fails >= self::MAX_FAILS) {
            RateLimiter::clear($key);
            RateLimiter::hit("$key:lock", self::LOCK_MINUTES * 60);
            throw $this->locked(Carbon::now()->addMinutes(self::LOCK_MINUTES));
        }
        throw $this->wrong(self::MAX_FAILS - $fails);
    }

    private static function unknownKey(string $email): string
    {
        return 'login-email:'.hash('sha256', Str::lower(trim($email)));
    }

    private function wrong(int $triesLeft): LoginRefused
    {
        return new LoginRefused('The email or password is wrong.', 422, [
            'errors' => ['email' => ['The email or password is wrong.']],
            'tries_left' => $triesLeft,
        ]);
    }

    private function locked(Carbon $until): LoginRefused
    {
        return new LoginRefused('Too many failed logins. The account is locked for '.self::LOCK_MINUTES.' minutes; a password reset unlocks it.', 429, [
            'locked_until' => $until->toIso8601String(),
        ]);
    }
}
