<?php

namespace App\Services\Ops;

/**
 * The environment guard of the staging-only ops commands (bridge:rotate-copied-tokens, bridge:verify-email): refused on
 * APP_ENV production, outside staging unless --force-env=staging (a non-production copy, e.g. local), and whenever
 * APP_URL is the live host or has no host.
 */
final class StagingOnlyGuard
{
    public const LIVE_HOST = 'bridge.tilenpoje.eu';

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
        if ($host === self::LIVE_HOST) {
            return 'APP_URL is the live Bridge ('.self::LIVE_HOST.'): refusing.';
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
