<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AccountService;
use App\Services\Auth\SignupBotCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Open sign-up (relayed decision 1): user + own workspace, verification mail, logged in. Boards work right away;
 * tokens and MCP wait for the verified email. 5 per IP and hour (route limiter `signup`) + SignupBotCheck.
 */
class SignupController extends Controller
{
    /** What the form needs: the signed start time, the Turnstile site key (null = no widget), the password minimum. */
    public function form(SignupBotCheck $bots): JsonResponse
    {
        return response()->json([
            'form_token' => $bots->formToken(),
            'turnstile_site_key' => config('turnstile.site_key'),
            'min_password' => AccountService::MIN_PASSWORD,
        ]);
    }

    public function store(Request $request, SignupBotCheck $bots, AccountService $accounts): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $bots->check($request->all(), $request->ip());
        $user = $accounts->register($data['name'], $data['email'], $data['password']);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => LoginController::user($user), 'redirect' => route('portfolio')], 201);
    }
}
