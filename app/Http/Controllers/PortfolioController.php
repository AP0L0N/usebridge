<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\HealthService;
use Inertia\Inertia;
use Inertia\Response;

class PortfolioController extends Controller
{
    public function __invoke(HealthService $health): Response
    {
        $projects = Project::query()->with(['owner', 'risks', 'statusUpdates', 'workItems'])->orderBy('name')->get()
            ->map(fn ($p) => $health->enrichProject($p))
            ->values();

        return Inertia::render('Portfolio', [
            'projects' => $projects,
        ]);
    }
}
