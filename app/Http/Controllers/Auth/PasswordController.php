<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

/** Reset (neutral request, broker token) and change (logged in) of the password, JSON (docs/MULTI-USER-PLAN.md §8). */
class PasswordController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    /** Same answer whether or not the email exists (and when throttled). */
    public const FORGOT_PER_EMAIL = 3;

    public const FORGOT_DECAY = 900;

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        // Per-email throttle on top of the per-IP route throttle: at most FORGOT_PER_EMAIL mails per address per
        // FORGOT_DECAY seconds, whatever IPs ask. Over the limit nothing is sent but the answer is the same generic 200,
        // so the response never tells whether the address exists or was throttled.
        $key = 'forgot-password:'.hash('sha256', mb_strtolower(trim($data['email'])));
        if (! RateLimiter::tooManyAttempts($key, self::FORGOT_PER_EMAIL)) {
            RateLimiter::hit($key, self::FORGOT_DECAY);
            $this->accounts->sendPasswordResetLink($data['email']);
        }

        return response()->json(['message' => 'If that email has an account, a reset link is on its way.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);
        $status = $this->accounts->resetPassword($data['email'], $data['token'], $data['password']);
        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'This reset link is invalid or has expired.',
                'expired' => true,
                'errors' => ['token' => ['This reset link is invalid or has expired.']],
            ], 422);
        }

        return response()->json(['message' => 'Password changed. Log in with the new password.']);
    }

    public function change(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string'],
            // The redesigned form sends it; older JSON clients may not.
            'password_confirmation' => ['sometimes', 'string', 'same:password'],
        ], ['password_confirmation.same' => __('account.password.mismatch')]);
        $this->accounts->changePassword($request->user(), $data['current_password'], $data['password'], $request->session()->getId());

        return response()->json(['message' => 'Password changed. Other devices were logged out.']);
    }
}
