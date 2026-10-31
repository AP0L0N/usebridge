<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\EmailChange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Profile (docs/MULTI-USER-PLAN.md §8 account profile): PATCH /account {name, timezone, email?, current_password?}
 * saves name and timezone at once; a different email starts an EmailChange (current password required) and the login
 * email stays the old one until the link is opened. Resend / cancel the pending change; the signed link itself.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly EmailChange $emails) {}

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'current_password' => ['sometimes', 'nullable', 'string'],
        ], [
            'name.required' => __('account.profile.name_required'),
            'name.max' => __('account.profile.name_max'),
            'timezone.*' => __('account.profile.timezone_invalid'),
            'email.*' => __('account.profile.email_invalid'),
        ]);
        /** @var User $user */
        $user = $request->user();
        // Email first: a wrong password or a taken address must not half-save the profile.
        $started = false;
        DB::transaction(function () use ($user, $data, &$started) {
            if (isset($data['email']) && trim($data['email']) !== '') {
                $started = $this->emails->request($user, $data['email'], $data['current_password'] ?? null);
            }
            $user->forceFill(['name' => trim($data['name']), 'timezone' => $data['timezone']])->save();
        });
        $user->refresh();

        return response()->json([
            'message' => $user->pending_email !== null && $started
                ? __('account.profile.saved_pending', ['email' => $user->pending_email])
                : __('account.profile.saved'),
            'user' => LoginController::user($user),
            'email_change_started' => $started,
        ]);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->emails->resend($user)) {
            return response()->json(['message' => __('account.email.none_pending')], 422);
        }

        return response()->json(['message' => __('account.email.resent', ['email' => $user->pending_email])]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $this->emails->cancel($request->user());

        return response()->json(['message' => __('account.email.kept'), 'user' => LoginController::user($request->user()->refresh())]);
    }

    /** The link from the mail to the new address: signed (60 min), works without being logged in on that browser. */
    public function verify(Request $request, string $id, string $hash): JsonResponse|RedirectResponse
    {
        $user = $request->hasValidSignature() ? User::query()->find($id) : null;
        $current = $user !== null && $request->user()?->is($user);
        $result = $user === null ? 'invalid' : $this->emails->confirm($user, $hash, $current ? $request->session()->getId() : null);

        if ($result !== 'ok') {
            $message = __($result === 'taken' ? 'account.email.link_taken' : 'account.email.link_invalid');

            return $request->wantsJson()
                ? response()->json(['message' => $message], 403)
                : redirect()->route($request->user() ? 'account.show' : 'login')->with('error', $message);
        }
        $message = __('account.email.changed', ['email' => $user->email]);

        return $request->wantsJson()
            ? response()->json(['message' => $message])
            : redirect()->route($current ? 'account.show' : 'login')->with('success', $message);
    }
}
