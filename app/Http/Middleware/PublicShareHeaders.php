<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global (bootstrap/app.php), so every response under /s/ (public share links) gets it, 404s, 429s and paths that match
 * no route included: never indexed or followed, no Referer leaves the page (the URL carries the token), never cached or
 * framed. Other paths pass through untouched.
 */
class PublicShareHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $request->is('s', 's/*')) {
            return $response;
        }
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");

        return $response;
    }
}
