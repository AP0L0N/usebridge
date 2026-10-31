<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Ticket;
use App\Services\Auth\AccountDeletion;
use App\Services\Auth\AccountService;
use App\Services\Auth\ApiTokens;
use App\Services\Auth\DeviceSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /account and GET /account/tokens: the settings screens (Inertia) or, for JSON clients, the existing JSON. Props
 * are the logged-in user's own account only; token props never carry a plaintext (it is only in the POST response).
 */
class AccountPageController extends Controller
{
    /** Short list for the profile select; the user's own zone is added when it is not in it. */
    public const TIMEZONES = ['Europe/London', 'Europe/Ljubljana', 'Europe/Berlin', 'America/New_York', 'America/Los_Angeles', 'Asia/Tokyo', 'UTC'];

    public function show(Request $request, DeviceSessions $sessions, ApiTokens $tokens): Response|JsonResponse
    {
        $user = $request->user();
        if ($request->wantsJson()) {
            return response()->json(['user' => LoginController::user($user)]);
        }

        return Inertia::render('Account/Settings', [
            'account' => LoginController::user($user),
            'sessions' => $sessions->list($user, $request->session()->getId()),
            'timezones' => array_values(array_unique([...self::TIMEZONES, ...($user->timezone ? [$user->timezone] : [])])),
            'minPassword' => AccountService::MIN_PASSWORD,
            'deletion' => [
                'graceDays' => AccountDeletion::GRACE_DAYS,
                // Own workspace only (EnsureWorkspaceGate set it; the scope filters every query).
                'counts' => ['projects' => Project::query()->count(), 'tickets' => Ticket::query()->count(), 'tokens' => count($tokens->list($user))],
            ],
            // Profile edit / email change are not built yet (§9 step 1); the form turns on when their routes exist.
            'can' => [
                'updateProfile' => Route::has('account.update'),
                'resendEmailChange' => Route::has('account.email.resend'),
                'cancelEmailChange' => Route::has('account.email.cancel'),
            ],
        ]);
    }

    public function tokens(Request $request, ApiTokens $tokens): Response|JsonResponse
    {
        $user = $request->user();
        if ($request->wantsJson()) {
            return response()->json(['data' => $tokens->list($user)]);
        }

        return Inertia::render('Account/Tokens', [
            'tokens' => $tokens->list($user),
            'mcpUrl' => self::mcpUrl(),
            'emailVerified' => $user->hasVerifiedEmail(),
            'expiryDays' => ApiTokens::EXPIRY_DAYS,
        ]);
    }

    public static function mcpUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/mcp';
    }
}
