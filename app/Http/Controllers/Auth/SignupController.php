<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AccountService;
use App\Services\Auth\SignupBotCheck;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Open sign-up (relayed decision 1): user + own workspace, verification mail, logged in. Boards work right away;
 * tokens and MCP wait for the verified email. 5 per IP and hour (route limiter `signup`) + SignupBotCheck. The
 * `accept_terms` checkbox (terms + age 18) is required; register() stores when and which terms version.
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
            'accept_terms' => ['accepted'],
        ], [
            'name.required' => __('auth_ui.errors.name_required'),
            'email.required' => __('auth_ui.errors.email_invalid'),
            'accept_terms.accepted' => __('auth_ui.errors.accept_terms'),
        ]);
        $bots->check($request->all(), $request->ip());
        try {
            $user = $accounts->register($data['name'], $data['email'], $data['password'], acceptedTerms: true);
        } catch (ValidationException $e) {
            throw self::screenErrors($e);
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => LoginController::user($user), 'redirect' => route('portfolio')], 201);
    }

    /**
     * The sign-up screen's wording for register()'s email errors (one line per field). A taken email answers
     * `email_taken: true`, so the screen shows "Log in instead" without matching on the message text.
     */
    private static function screenErrors(ValidationException $e): ValidationException|HttpResponseException
    {
        $failed = $e->validator->failed()['email'] ?? [];
        if ($failed === []) {
            return $e;
        }
        $errors = $e->errors();
        $taken = isset($failed['Unique']);
        $errors['email'] = [__($taken ? 'auth_ui.errors.email_taken' : 'auth_ui.errors.email_invalid')];

        return new HttpResponseException(response()->json([
            'message' => $errors['email'][0],
            'errors' => $errors,
            'email_taken' => $taken,
        ], 422));
    }
}
