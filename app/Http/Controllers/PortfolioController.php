<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProjectStatus;
use Inertia\Inertia;
use Inertia\Response;

class PortfolioController extends Controller
{
    public function __invoke(): Response
    {
        $projects = Project::query()->with(['statusUpdates', 'requiredInputs'])->get()
            ->map(fn ($p) => ProjectStatus::summary($p))
            ->values();

        return Inertia::render('Portfolio', [
            'projects' => $projects,
            'now' => now()->toIso8601String(),
        ]);
    }
}
