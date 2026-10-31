<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AccountService;
use App\Services\Auth\SignupBotCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The logged-out screens (docs/MULTI-USER-PLAN.md §8): Inertia pages only. Every write goes to the existing JSON
 * routes (POST /login, /signup, /forgot-password, /reset-password); nothing here changes state.
 */
class AuthPageController extends Controller
{
    public function login(): Response|RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return redirect()->route('portfolio');
        }

        return Inertia::render('Auth/Login', [
            // AUTH-1 stays usable for the existing workspace until Tilen retires it ("Workspace password" link).
            'gateAvailable' => (string) config('bridge.gate_password') !== '',
        ]);
    }

    public function signup(SignupBotCheck $bots): Response|RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return redirect()->route('portfolio');
        }

        return Inertia::render('Auth/Signup', [
            'formToken' => $bots->formToken(),
            // null = no widget (and no script); the honeypot + minimum fill time always apply.
            'turnstileSiteKey' => config('turnstile.site_key') ?: null,
            'minPassword' => AccountService::MIN_PASSWORD,
        ]);
    }

    public function forgot(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    /** The link from the reset mail (AppServiceProvider::createUrlUsing): /reset-password/{token}?email=… */
    public function reset(Request $request, string $token): Response
    {
        $email = Str::lower(trim((string) $request->query('email', '')));
        $user = $email === '' ? null : Password::broker()->getUser(['email' => $email]);

        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $email,
            // Same answer for an unknown email and a used / expired token, so the page reveals nothing new.
            'valid' => $user instanceof User && Password::broker()->tokenExists($user, $token),
            'minPassword' => AccountService::MIN_PASSWORD,
        ]);
    }
}
