<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Usage\InvalidUsageDay;
use App\Services\Usage\UsageDayImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageDayController extends Controller
{
    /**
     * Raw body = one estimator day file. 201 created / 200 replaced, 422 with errors when invalid.
     * The body is never logged (only validated and stored).
     */
    public function store(Request $request, UsageDayImporter $importer): JsonResponse
    {
        try {
            $result = $importer->import($request->getContent());
        } catch (InvalidUsageDay $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], 422);
        }

        return response()->json(['data' => $result], $result['created'] ? 201 : 200);
    }
}
