<?php

namespace App\Http\Controllers;

use App\Models\OpsDigestItem;
use Inertia\Inertia;
use Inertia\Response;

class OpsController extends Controller
{
    public function __invoke(): Response
    {
        $items = OpsDigestItem::query()->with('project')->orderByDesc('created_at')->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'kind' => $i->kind,
                'summary' => $i->summary,
                'projectSlug' => $i->project?->slug,
                'createdAt' => optional($i->created_at)?->toIso8601String(),
            ]);

        return Inertia::render('Ops', [
            'items' => $items,
            'leadOrbitConnected' => false,
        ]);
    }
}
