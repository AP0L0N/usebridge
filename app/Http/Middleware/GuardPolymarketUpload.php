<?php

namespace App\Http\Middleware;

use App\Services\Polymarket\PolymarketFeedValidator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/polymarket/feed: 413 above 1 MB (header and actual body), 415 unless application/json.
 * Same idea as GuardUsageUpload; TrimStrings / ConvertEmptyStringsToNull skip this route
 * (bootstrap/app.php) so the JSON is decoded only by PolymarketFeedValidator.
 */
class GuardPolymarketUpload
{
    public function handle(Request $request, Closure $next): Response
    {
        $max = PolymarketFeedValidator::MAX_BYTES;

        $declared = $request->headers->get('Content-Length');
        if ($declared !== null && (! ctype_digit($declared) || (int) $declared > $max)) {
            return $this->error(413, 'The body is larger than 1 MB.');
        }

        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        if ($type !== 'application/json') {
            return $this->error(415, 'Content-Type must be application/json.');
        }

        if (strlen($request->getContent()) > $max) {
            return $this->error(413, 'The body is larger than 1 MB.');
        }

        return $next($request);
    }

    private function error(int $status, string $message): Response
    {
        return response()->json(['message' => $message], $status);
    }
}
