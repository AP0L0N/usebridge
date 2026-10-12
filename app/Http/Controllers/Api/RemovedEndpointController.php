<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** r0 endpoints removed in Bridge r1 (milestones, work items, risks, CEO queue, ops digest). */
class RemovedEndpointController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'message' => 'Removed in Bridge r1. Post status updates to /api/status-updates; request inputs via its `inputs` field.',
        ], 410);
    }
}
