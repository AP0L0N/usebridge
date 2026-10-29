<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tickets cutover: with config('bridge.legacy_read_only') on, writes to Questions / Required inputs / Next steps / CEO queue answer 410.
 * Reads keep working (history). Off by default so phase 1 changes nothing until the UI switches to tickets.
 */
class LegacyReadOnly
{
    public const MESSAGE = 'Superseded by tickets; see docs/API-TICKETS.md';

    public function handle(Request $request, Closure $next): Response
    {
        if (config('bridge.legacy_read_only') && ! $request->isMethodSafe()) {
            return response()->json(['message' => self::MESSAGE], 410);
        }

        return $next($request);
    }
}
