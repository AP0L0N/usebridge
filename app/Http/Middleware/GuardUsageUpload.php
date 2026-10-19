<?php

namespace App\Http\Middleware;

use App\Services\Usage\UsageDayValidator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/usage/days: 413 above 512 KB (header and actual body), 415 unless application/json.
 * Runs before anything decodes the body; TrimStrings / ConvertEmptyStringsToNull skip this
 * route (bootstrap/app.php) so the JSON is decoded only by UsageDayValidator.
 */
class GuardUsageUpload
{
    public function handle(Request $request, Closure $next): Response
    {
        $max = UsageDayValidator::MAX_BYTES;

        $declared = $request->headers->get('Content-Length');
        if ($declared !== null && (! ctype_digit($declared) || (int) $declared > $max)) {
            return $this->error(413, 'The file is larger than 512 KB.');
        }

        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        if ($type !== 'application/json') {
            return $this->error(415, 'Content-Type must be application/json.');
        }

        if (strlen($request->getContent()) > $max) {
            return $this->error(413, 'The file is larger than 512 KB.');
        }

        return $next($request);
    }

    private function error(int $status, string $message): Response
    {
        return response()->json(['message' => $message], $status);
    }
}
