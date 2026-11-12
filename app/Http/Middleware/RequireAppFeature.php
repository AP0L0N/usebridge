<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * App-wide switch for a hidden feature (config bridge.features.*, e.g. BRIDGE_FEATURE_POWER_SAVER): when it is off the
 * web route redirects to /portfolio (302) and the nav leaves it out (HandleInertiaRequests). Nothing is deleted.
 */
class RequireAppFeature
{
    public static function enabled(string $feature): bool
    {
        return (bool) config("bridge.features.$feature", false);
    }

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! self::enabled($feature)) {
            return redirect('/portfolio');
        }

        return $next($request);
    }
}
