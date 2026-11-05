<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePowerSaverThresholds;
use App\Models\User;
use App\Services\PowerSaver\PowerSaverCatalog;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Power Saver page (session). Lists every project and agent routine; writes thresholds only. */
class PowerSaverController extends Controller
{
    public function __construct(private readonly PowerSaverCatalog $catalog) {}

    public function show(): Response
    {
        return Inertia::render('PowerSaver', $this->catalog->pageProps());
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
