<?php

namespace App\Http\Middleware;

use App\Support\TicketRefs;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST API reads: stored `[[ticket:<id>|<key>]]` tokens come back as the ticket's current plain key ("BR-9") in every
 * JSON string, resolved in one query per response. Tokens hold only [A-Za-z0-9_|:-] and keys only [A-Z0-9-], so the
 * swap is safe on the encoded body and keeps its exact shape. API clients never see tokens.
 */
class ExpandTicketRefs
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response instanceof JsonResponse && str_contains((string) $response->getContent(), '[[ticket:')) {
            $response->setContent(TicketRefs::expand($response->getContent()));
        }

        return $response;
    }
}
