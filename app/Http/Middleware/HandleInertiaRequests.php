<?php

namespace App\Http\Middleware;

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
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'projectsNav' => function () use ($request) {
                if (! $request->session()->get('bridge_gate_unlocked')) {
                    return [];
                }

                return \App\Models\Project::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'slug'])
                    ->map(fn ($p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'slug' => $p->slug,
                    ])
                    ->all();
            },
        ];
    }
}
