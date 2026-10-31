<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Accounts without screens yet (docs/MULTI-USER-PLAN.md §2): sign up (user + own workspace as owner), password reset
 * through the built-in broker (password_reset_tokens, 60 min, 60 s throttle) and password change. Controllers for the
 * login/signup/reset screens call this; login itself is Auth::attempt + session regenerate in the controller.
 */
final class AccountService
{
    public const MIN_PASSWORD = 12;

    public static function passwordRule(): PasswordRule
    {
        return PasswordRule::min(self::MIN_PASSWORD);
    }

    /**
     * New user and their own workspace (owner). Features off (no Polymarket).
     *
     * @throws ValidationException
     */
    public function register(string $name, string $email, string $password, ?string $workspaceName = null): User
    {
        $data = Validator::make(
            ['name' => $name, 'email' => Str::lower(trim($email)), 'password' => $password, 'workspace' => $workspaceName ?? $name],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', self::passwordRule()],
                'workspace' => ['required', 'string', 'max:120'],
            ],
        )->validate();

        return DB::transaction(function () use ($data) {
            $user = User::query()->create(['name' => trim($data['name']), 'email' => $data['email'], 'password' => $data['password']]);
            $workspace = Workspace::query()->create([
                'slug' => $this->freeSlug($data['workspace']),
                'name' => trim($data['workspace']),
                'owner_user_id' => $user->id,
                'features' => [],
            ]);
            $workspace->users()->attach($user->id, ['role' => 'owner']);

            return $user;
        });
    }

    /**
     * Mails a reset link when the email is known. Returns the broker status (Password::RESET_LINK_SENT, INVALID_USER,
     * RESET_THROTTLED); the screen must show the same message either way so emails cannot be probed.
     */
    public function sendPasswordResetLink(string $email): string
    {
        return Password::broker()->sendResetLink(['email' => Str::lower(trim($email))]);
    }

    /**
     * Completes a reset: new password, new remember token (other "remember me" sessions end). Returns the broker
     * status (Password::PASSWORD_RESET on success, INVALID_TOKEN / INVALID_USER otherwise).
     *
     * @throws ValidationException when the new password is too weak
     */
    public function resetPassword(string $email, string $token, string $password): string
    {
        Validator::make(['password' => $password], ['password' => ['required', 'string', self::passwordRule()]])->validate();

        return Password::broker()->reset(
            ['email' => Str::lower(trim($email)), 'token' => $token, 'password' => $password],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
            },
        );
    }

    /** @throws ValidationException wrong current password or too weak a new one */
    public function changePassword(User $user, string $current, string $new): void
    {
        if (! Hash::check($current, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is wrong.']);
        }
        Validator::make(['password' => $new], ['password' => ['required', 'string', self::passwordRule()]])->validate();
        $user->forceFill(['password' => $new, 'remember_token' => Str::random(60)])->save();
    }

    private function freeSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $base = Str::limit($base, 56, '');
        $slug = $base;
        for ($i = 2; Workspace::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "$base-$i";
        }

        return $slug;
    }
}
