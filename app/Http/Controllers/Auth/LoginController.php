<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AccountDeletion;
use App\Services\Auth\LoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** JSON for the login screen (docs/MULTI-USER-PLAN.md §8); errors come from LoginRefused (422 / 429 / 403). */
class LoginController extends Controller
{
    public function __construct(private readonly LoginService $login) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ]);
        $user = $this->login->login($data['email'], $data['password'], (bool) ($data['remember'] ?? false), $request->ip(), $request->session());

        // Unverified: the verify notice first (resend there; the boards still work without verifying).
        return response()->json(['user' => self::user($user), 'redirect' => route($user->hasVerifiedEmail() ? 'portfolio' : 'verification.notice')]);
    }

    public function cancelDeletion(Request $request, AccountDeletion $deletion): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $user = $this->login->cancelDeletionAndLogin($data['email'], $data['password'], $request->ip(), $request->session(), $deletion);

        return response()->json(['user' => self::user($user), 'message' => 'The deletion is cancelled.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->login->logout($request->session());

        return response()->json(['message' => 'Logged out.']);
    }

    /** The logged-in user's own account (never another user's). */
    public static function user(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'pending_email' => $user->pending_email,
            'timezone' => $user->timezone,
            'is_admin' => $user->is_admin,
            'delete_requested_at' => $user->delete_requested_at?->toIso8601String(),
        ];
    }
}
