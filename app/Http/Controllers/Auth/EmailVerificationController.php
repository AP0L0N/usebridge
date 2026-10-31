<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Email verification (MustVerifyEmail + the built-in VerifyEmail notification, docs/MULTI-USER-PLAN.md §2). The link
 * is a signed URL (60 min), so it works without being logged in on that browser. Verified is needed for tokens / MCP.
 */
class EmailVerificationController extends Controller
{
    /** verification.notice: where the `verified` middleware sends unverified users. */
    public function notice(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'verified' => $user?->hasVerifiedEmail() ?? false,
            'message' => 'Verify your email address: follow the link we sent you.',
        ], $user?->hasVerifiedEmail() ? 200 : 403);
    }

    /** JSON for JSON clients; a browser following the mail link lands on API tokens (or login) with a flash. */
    public function verify(Request $request, string $id, string $hash): JsonResponse|RedirectResponse
    {
        $user = User::query()->find($id);
        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return $request->wantsJson()
                ? response()->json(['message' => 'This verification link is invalid.'], 403)
                : redirect()->route('login')->with('error', 'This verification link is invalid.');
        }
        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }
        if ($request->wantsJson()) {
            return response()->json(['message' => 'Email verified.', 'verified' => true]);
        }

        return redirect()->route($request->user()?->is($user) ? 'account.tokens' : 'login')
            ->with('success', 'Email verified. You can create API tokens now.');
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'If your email is not verified yet, a new link is on its way.']);
    }
}
