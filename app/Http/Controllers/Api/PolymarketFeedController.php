<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Polymarket\InvalidPolymarketFeed;
use App\Services\Polymarket\PolymarketFeedImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PolymarketFeedController extends Controller
{
    /**
     * Body `{snapshot, series}` (Captain posts Frank's feed.json + book_series.json). 201 when the snapshot
     * row is created for the first time, else 200; 422 with errors when invalid. The body is never logged.
     */
    public function store(Request $request, PolymarketFeedImporter $importer): JsonResponse
    {
        try {
            $result = $importer->importBody($request->getContent());
        } catch (InvalidPolymarketFeed $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], 422);
        }

        return response()->json(['data' => $result], $result['snapshot'] === 'created' ? 201 : 200);
    }
}
