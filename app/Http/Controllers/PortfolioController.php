<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProjectStatus;
use App\Services\Tickets\Actor;
use Inertia\Inertia;
use Inertia\Response;

class PortfolioController extends Controller
{
    public function __invoke(): Response
    {
        $names = Actor::names();
        $projects = Project::query()
            ->with(['statusUpdates', 'requiredInputs', 'questions', 'tickets' => fn ($q) => $q->select(['id', 'project_id', 'status', 'archived_at'])])
            ->get()
            ->map(fn ($p) => ProjectStatus::summary($p, null, $names))
            ->values();

        return Inertia::render('Portfolio', [
            'projects' => $projects,
            'now' => now()->toIso8601String(),
        ]);
    }
}
