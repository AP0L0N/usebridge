<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Server-side Turnstile check: POST siteverify {secret, response, remoteip}. Fails closed once a secret is configured. */
final class TurnstileVerifier
{
    public function enabled(): bool
    {
        return filled(config('turnstile.secret_key'));
    }

    public function verify(?string $response, ?string $ip): bool
    {
        if (! $this->enabled()) {
            return true;
        }
        if (blank($response)) {
            return false;
        }
        try {
            $result = Http::asForm()->timeout((int) config('turnstile.timeout', 5))->post((string) config('turnstile.verify_url'), array_filter([
                'secret' => (string) config('turnstile.secret_key'),
                'response' => $response,
                'remoteip' => $ip,
            ]));
        } catch (Throwable) {
            return false;
        }

        return $result->successful() && $result->json('success') === true;
    }
}
