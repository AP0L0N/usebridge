<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\RequiredInput;
use App\Services\InputResolution;
use Illuminate\Http\Request;
use App\Services\ProjectStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public const DONE_VISIBLE_DAYS = 2;

    public const TIMELINE_STEP_DAYS = 7;

    public function show(Request $request, string $slug): Response
    {
        $project = Project::query()
            ->where('slug', $slug)
            ->with(['requiredInputs', 'nextSteps' => fn ($q) => $q->whereNull('done_at')])
            ->firstOrFail();

        // r3: timeline window in whole 7-day steps (?days=7|14|21...).
        $days = (int) $request->query('days', self::TIMELINE_STEP_DAYS);
        $days = min(3650, max(self::TIMELINE_STEP_DAYS, (int) (ceil($days / self::TIMELINE_STEP_DAYS) * self::TIMELINE_STEP_DAYS)));
        $cutoff = now()->subDays($days);

        $inputs = $project->requiredInputs;
        $open = $inputs->whereNull('resolved_at')->sortBy(fn ($i) => $i->since->getTimestamp())->values();
        // r3: cleared inputs disappear 2 days after they were resolved.
        $doneCutoff = now()->subDays(self::DONE_VISIBLE_DAYS)->getTimestamp();
        $done = $inputs->whereNotNull('resolved_at')
            ->filter(fn ($i) => $i->resolved_at->getTimestamp() >= $doneCutoff)
            ->sortByDesc(fn ($i) => $i->resolved_at->getTimestamp())->values();

        $updates = $project->statusUpdates()->with('agent')
            ->where('created_at', '>=', $cutoff)
            ->orderByDesc('created_at')->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'body' => $u->body,
                'agentName' => $u->agent?->name ?? $u->agent_id,
                'createdAt' => $u->created_at?->toIso8601String(),
            ]);
        $hasOlder = $project->statusUpdates()->where('created_at', '<', $cutoff)->exists();

        $project->load('statusUpdates');
        $summary = ProjectStatus::summary($project);
        unset($summary['searchText']);

        $steps = $project->nextSteps->sortBy([['position', 'asc'], [fn ($a, $b) => $a->created_at <=> $b->created_at]])->values();

        return Inertia::render('Project', [
            'project' => $summary,
            'statusUpdates' => $updates,
            'timeline' => ['days' => $days, 'step' => self::TIMELINE_STEP_DAYS, 'hasMore' => $hasOlder],
            'openInputs' => $open->map(fn ($i) => ProjectStatus::inputView($i)),
            'doneInputs' => $done->map(fn ($i) => ProjectStatus::inputView($i)),
            'nextSteps' => [
                'inProgress' => $steps->where('kind', 'in_progress')->values()->map->toView(),
                'blockers' => $steps->where('kind', 'blocker')->values()->map->toView(),
            ],
            'now' => now()->toIso8601String(),
        ]);
    }

    /** Gate-session tick/untick (CSRF-protected web route). resolved_by = 'tilen'. */
    public function resolveInput(Request $request, string $slug, string $id): RedirectResponse
    {
        $data = $request->validate(['resolved' => ['required', 'boolean']]);
        $project = Project::query()->where('slug', $slug)->firstOrFail();
        $input = RequiredInput::query()->where('project_id', $project->id)->findOrFail($id);
        $data['resolved'] ? InputResolution::resolve($input, InputResolution::TILEN) : InputResolution::reopen($input);

        return back(303);
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
