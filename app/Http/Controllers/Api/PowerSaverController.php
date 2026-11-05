<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePowerSaverThresholds;
use App\Models\User;
use App\Services\PowerSaver\PowerSaverCatalog;
use Illuminate\Http\JsonResponse;

/**
 * Power Saver for Captain's enforcement routine.
 * GET needs boards:read (or * / ceo). PATCH needs boards:write. Same body as the page.
 * Reading does not change projects or routines. Writing changes only stored thresholds.
 */
class PowerSaverController extends Controller
{
    public function __construct(private readonly PowerSaverCatalog $catalog) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->catalog->apiDocument()]);
    }

    public function update(UpdatePowerSaverThresholds $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return response()->json([
            'data' => $this->catalog->save($user, $request->validated('thresholds')),
        ]);
    }
}
