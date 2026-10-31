<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Breached-password check (docs/MULTI-USER-PLAN.md §0, CoS decision 4): HIBP k-anonymity range API. Only the first 5
 * hex chars of the SHA-1 leave the box; the response (padded) is searched locally for the rest. Fail-open: any error,
 * non-2xx or timeout lets the password through and logs a warning without the password or its hash.
 */
final class BreachedPasswords
{
    public const RANGE_URL = 'https://api.pwnedpasswords.com/range/';

    public function enabled(): bool
    {
        return (bool) config('bridge.hibp_enabled', true);
    }

    public function isBreached(string $password): bool
    {
        if (! $this->enabled() || $password === '') {
            return false;
        }
        $hash = strtoupper(sha1($password));
        [$prefix, $suffix] = [substr($hash, 0, 5), substr($hash, 5)];

        try {
            $response = Http::withHeaders(['Add-Padding' => 'true'])
                ->timeout((int) config('bridge.hibp_timeout', 3))
                ->get(self::RANGE_URL.$prefix);
        } catch (Throwable $e) {
            Log::warning('HIBP range check failed, password allowed (fail-open)', ['error' => $e::class]);

            return false;
        }
        if (! $response->successful()) {
            Log::warning('HIBP range check failed, password allowed (fail-open)', ['status' => $response->status()]);

            return false;
        }

        foreach (preg_split('/\R/', $response->body()) as $line) {
            [$candidate, $count] = array_pad(explode(':', trim($line), 2), 2, '0');
            // Padding rows have a count of 0.
            if ($candidate === $suffix && (int) $count > 0) {
                return true;
            }
        }

        return false;
    }
}
