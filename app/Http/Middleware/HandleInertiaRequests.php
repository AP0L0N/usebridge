<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'gateUnlocked' => (bool) $request->session()->get('bridge_gate_unlocked', false),
            // Lazy: resolved at render time, after the gate set the workspace (false on the gate page).
            'features' => fn () => [
                Workspace::FEATURE_POLYMARKET => app(CurrentWorkspace::class)->hasFeature(Workspace::FEATURE_POLYMARKET),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
