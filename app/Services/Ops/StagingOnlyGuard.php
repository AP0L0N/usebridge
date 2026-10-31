<?php

namespace App\Services\Ops;

/**
 * The environment guard of the staging-only ops commands (bridge:rotate-copied-tokens, bridge:verify-email): refused on
 * APP_ENV production, outside staging unless --force-env=staging (a non-production copy, e.g. local), and whenever
 * APP_URL is the live host or has no host.
 */
final class StagingOnlyGuard
{
    /** The live Bridge host (config bridge.live_host / env BRIDGE_LIVE_HOST; '' = no live-host check). */
    public static function liveHost(): string
    {
        return self::host('https://'.trim((string) config('bridge.live_host', '')));
    }

    /** Why this run is refused, or null when it may go ahead. */
    public static function refusal(string $env, ?string $forceEnv, ?string $appUrl): ?string
    {
        if ($env === 'production') {
            return 'APP_ENV is production: refusing.';
        }
        if ($forceEnv !== null && $forceEnv !== 'staging') {
            return '--force-env only accepts "staging".';
        }
        if ($env !== 'staging' && $forceEnv === null) {
            return "APP_ENV is '$env', not staging: refusing (--force-env=staging on a non-production copy).";
        }
        $host = self::host((string) $appUrl);
        if ($host === '') {
            return 'APP_URL has no host: refusing.';
        }
        $live = self::liveHost();
        if ($live !== '' && $host === $live) {
            return 'APP_URL is the live Bridge ('.$live.'): refusing.';
        }

        return null;
    }

    /** Lower-case host of a URL without a trailing dot or leading "www." ('' when there is none). */
    private static function host(string $url): string
    {
        $host = rtrim(strtolower((string) parse_url(trim($url), PHP_URL_HOST)), '.');

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
