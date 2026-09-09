<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Project;
use App\Services\HealthService;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function show(string $slug, HealthService $health): Response
    {
        $project = Project::query()
            ->where('slug', $slug)
            ->with(['owner', 'milestones', 'workItems.owner', 'risks', 'statusUpdates.agent'])
            ->firstOrFail();

        $view = $health->enrichProject($project);

        $milestones = $project->milestones->map(fn ($m) => [
            'id' => $m->id,
            'title' => $m->title,
            'dueAt' => optional($m->due_at)?->format('Y-m-d'),
            'status' => $m->status,
            'lane' => $health->milestoneLane($m),
        ]);

        $workItems = $project->workItems->map(fn ($w) => [
            'id' => $w->id,
            'title' => $w->title,
            'ownerName' => $w->owner?->name ?? $w->owner_agent_id,
            'dueAt' => optional($w->due_at)?->format('Y-m-d'),
            'status' => $w->status,
            'doneLooksLike' => $w->done_looks_like,
        ]);

        $risks = $project->risks->map(fn ($r) => [
            'id' => $r->id,
            'text' => $r->text,
            'severity' => $r->severity,
            'open' => (bool) $r->open,
        ]);

        $updates = $project->statusUpdates->sortByDesc('created_at')->values()->map(fn ($u) => [
            'id' => $u->id,
            'body' => $u->body,
            'agentName' => $u->agent?->name ?? $u->agent_id,
            'createdAt' => optional($u->created_at)?->timezone('Europe/Ljubljana')->format('Y-m-d'),
        ]);

        return Inertia::render('Cockpit', [
            'project' => $view,
            'milestones' => $milestones,
            'workItems' => $workItems,
            'risks' => $risks,
            'statusUpdates' => $updates,
            'agents' => Agent::query()->orderBy('name')->get(['id', 'name', 'role_label'])
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'roleLabel' => $a->role_label,
                ]),
        ]);
    }
}
