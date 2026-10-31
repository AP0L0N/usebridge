<?php

namespace App\Services\Auth;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/**
 * Bot check on the sign-up form (docs/MULTI-USER-PLAN.md §0, relayed decision 1), on top of 5 sign-ups per IP and hour:
 * - honeypot: the hidden `website` field must stay empty;
 * - minimum fill time: `form_token` is an encrypted timestamp from GET /signup/form; it must be >= 3 s and <= 1 day old;
 * - Cloudflare Turnstile (`cf-turnstile-response`) when a secret is configured.
 */
final class SignupBotCheck
{
    public const HONEYPOT = 'website';

    public const MIN_SECONDS = 3;

    public const MAX_SECONDS = 86400;

    public function __construct(private readonly TurnstileVerifier $turnstile) {}

    public function formToken(): string
    {
        return Crypt::encryptString((string) Carbon::now()->getTimestamp());
    }

    /** @throws ValidationException */
    public function check(array $input, ?string $ip): void
    {
        if (filled($input[self::HONEYPOT] ?? null)) {
            throw ValidationException::withMessages(['form' => 'Sign-up failed. Please try again.']);
        }
        try {
            $issued = (int) Crypt::decryptString((string) ($input['form_token'] ?? ''));
        } catch (DecryptException) {
            throw ValidationException::withMessages(['form' => 'The form expired. Reload the page and try again.']);
        }
        $age = Carbon::now()->getTimestamp() - $issued;
        if ($age < self::MIN_SECONDS) {
            throw ValidationException::withMessages(['form' => 'That was quick. Please try again.']);
        }
        if ($age > self::MAX_SECONDS) {
            throw ValidationException::withMessages(['form' => 'The form expired. Reload the page and try again.']);
        }
        if (! $this->turnstile->verify($input['cf-turnstile-response'] ?? null, $ip)) {
            throw ValidationException::withMessages(['turnstile' => 'The bot check failed. Please try again.']);
        }
    }
}
