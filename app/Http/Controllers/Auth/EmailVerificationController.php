<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Pages\AuthPageController;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Email verification (MustVerifyEmail + the built-in VerifyEmail notification, docs/MULTI-USER-PLAN.md §2). The link
 * is a signed URL (60 min), so it works without being logged in on that browser. Verified is needed for tokens / MCP.
 */
class EmailVerificationController extends Controller
{
    /**
     * verification.notice: where unverified users land after login. JSON clients keep the JSON; a browser gets the
     * VerifyEmail screen (resend, throttled 6/min), guests go to login and verified users to their boards.
     */
    public function notice(Request $request): JsonResponse|RedirectResponse|Response
    {
        $user = $request->user();
        if (! $request->wantsJson()) {
            if ($user === null) {
                return redirect()->guest(route('login'));
            }
            if ($user->hasVerifiedEmail()) {
                return redirect()->route('portfolio');
            }

            return $this->noticePage($user);
        }

        return response()->json([
            'verified' => $user?->hasVerifiedEmail() ?? false,
            'message' => 'Verify your email address: follow the link we sent you.',
        ], $user?->hasVerifiedEmail() ? 200 : 403);
    }

    /** JSON for JSON clients; a browser following the mail link lands on API tokens (or login) with a flash. */
    public function verify(Request $request, string $id, string $hash): JsonResponse|RedirectResponse|Response
    {
        $user = $request->hasValidSignature() ? User::query()->find($id) : null;
        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return $this->invalidLink($request);
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

    /**
     * S5: tampered, expired or someone else's link. JSON keeps the 403; a browser of a logged-in, unverified user gets
     * the verify page with the message and its resend button; a verified user goes to the boards; a guest goes to login
     * (with the message) and from there lands on the verify page after logging in.
     */
    private function invalidLink(Request $request): JsonResponse|RedirectResponse|Response
    {
        $copy = AuthPageController::copy()['verify'];
        if ($request->wantsJson()) {
            return response()->json(['message' => 'This verification link is invalid or has expired.', 'expired' => true], 403);
        }
        $current = $request->user();
        if ($current === null) {
            $request->session()->put('url.intended', route('verification.notice'));

            return redirect()->route('login')->with('error', $copy['link_invalid_guest']);
        }
        if ($current->hasVerifiedEmail()) {
            return redirect()->route('portfolio');
        }

        return $this->noticePage($current, $copy['link_invalid']);
    }

    private function noticePage(User $user, ?string $linkError = null): Response
    {
        return Inertia::render('Auth/VerifyEmail', [
            'email' => $user->email,
            'resendUrl' => route('verification.send', absolute: false),
            'linkError' => $linkError,
            'copy' => AuthPageController::copy(),
        ]);
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
