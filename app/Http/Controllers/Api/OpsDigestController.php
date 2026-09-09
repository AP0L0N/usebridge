<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OpsDigestItem;
use Illuminate\Http\JsonResponse;

class OpsDigestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => OpsDigestItem::query()->orderByDesc('created_at')->get(),
            'lead_orbit_connected' => false,
            'message' => 'Lead Orbit adapter stub — empty until connected.',
        ]);
    }
}
