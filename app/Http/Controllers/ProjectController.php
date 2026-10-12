<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProjectStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function show(string $slug): Response
    {
        $project = Project::query()
            ->where('slug', $slug)
            ->with(['statusUpdates.agent', 'requiredInputs'])
            ->firstOrFail();

        $inputs = $project->requiredInputs;
        $open = $inputs->whereNull('done_at')->sortBy(fn ($i) => $i->since->getTimestamp())->values();
        $done = $inputs->whereNotNull('done_at')->sortByDesc(fn ($i) => $i->done_at->getTimestamp())->values();

        $updates = $project->statusUpdates
            ->sortByDesc(fn ($u) => $u->created_at?->getTimestamp() ?? 0)
            ->values()
            ->map(fn ($u) => [
                'id' => $u->id,
                'body' => $u->body,
                'agentName' => $u->agent?->name ?? $u->agent_id,
                'createdAt' => $u->created_at?->toIso8601String(),
            ]);

        $summary = ProjectStatus::summary($project);
        unset($summary['searchText']);

        return Inertia::render('Project', [
            'project' => $summary,
            'statusUpdates' => $updates,
            'openInputs' => $open->map(fn ($i) => ProjectStatus::inputView($i)),
            'doneInputs' => $done->map(fn ($i) => ProjectStatus::inputView($i)),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function archive(string $slug): RedirectResponse
    {
        $project = Project::query()->where('slug', $slug)->firstOrFail();
        $project->update(['archived_at' => $project->archived_at ?? Carbon::now(), 'reopened_at' => null]);

        return back();
    }

    public function unarchive(string $slug): RedirectResponse
    {
        Project::query()->where('slug', $slug)->firstOrFail()->update(['archived_at' => null]);

        return back();
    }
}
