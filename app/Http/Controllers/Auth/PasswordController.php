<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/** Reset (neutral request, broker token) and change (logged in) of the password, JSON (docs/MULTI-USER-PLAN.md §8). */
class PasswordController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    /** Same answer whether or not the email exists (and when throttled). */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $this->accounts->sendPasswordResetLink($data['email']);

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
        ]);
        $this->accounts->changePassword($request->user(), $data['current_password'], $data['password'], $request->session()->getId());

        return response()->json(['message' => 'Password changed. Other devices were logged out.']);
    }
}
