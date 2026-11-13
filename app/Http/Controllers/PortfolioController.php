<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectStatusEvent;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\Workstream;
use App\Services\ProjectStatus;
use App\Services\Tickets\Actor;
use App\Services\Tickets\OpenAsks;
use App\Services\Tickets\Questions;
use App\Services\Tickets\Workstreams;
use App\Support\BoardColumns;
use App\Support\Onboarding\ReadyBanner;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PortfolioController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $names = Actor::names();
        $projects = Project::query()
            ->with(['statusUpdates', 'requiredInputs', 'questions', 'tickets' => fn ($q) => $q->select(['id', 'project_id', 'status', 'done_at', 'archived_at'])])
            ->withCount(OpenAsks::withCount())
            ->get();
        Questions::loadOpenCounts($projects);
        // Where the current status came from: the latest status event's source (api = REST agent token, mcp = the MCP server, ui = the web app).
        $sources = ProjectStatusEvent::query()->whereIn('project_id', $projects->modelKeys())
            ->orderByDesc('created_at')->orderByDesc('id')->get(['project_id', 'source'])
            ->unique('project_id')->pluck('source', 'project_id');
        $user = $request->user();
        $workstreams = Workstreams::rowsFor($projects, $user);
        $models = $projects->keyBy('id');
        $projects = $projects
            ->map(fn (Project $p) => [
                ...ProjectStatus::summary($p, null, $names),
                'columnLabels' => $p->column_labels ?? (object) [],
                'pmStatusSource' => $p->status_set_at ? ($sources[$p->id] ?? null) : null,
                'createdAt' => $p->created_at?->toIso8601String(),
                // Finder column 2 (README §1.1, §2): workstreams in board order with the viewer's badges; the project dot is
                // "any badge" (archived projects have none).
                'workstreams' => $workstreams[$p->id] ?? [],
                'hasChanges' => collect($workstreams[$p->id] ?? [])->contains(fn (array $w) => $w['badge'] > 0),
            ])
            ->sort(self::order(...))
            ->values();
        $selected = is_string($request->query('project')) ? $request->query('project') : null;

        return Inertia::render('Portfolio', [
            'projects' => $projects,
            // Finder selection (/portfolio?project={slug}); column 3's change list loads for it only (partial reload).
            'selected' => $selected,
            'preview' => fn () => $selected !== null && ($project = $models->firstWhere('slug', $selected)) ? self::preview($project, $user, $names) : null,
            'now' => now()->toIso8601String(),
            // ?ready={slug} after onboarding: banner + connect card (null otherwise).
            'welcome' => ReadyBanner::props($request),
            // The workspace's default column names (each card prefers its project's own columnLabels).
            'columns' => array_map(fn (array $c) => ['key' => $c['key'], 'label' => $c['label']], BoardColumns::all()),
        ]);
    }

    /**
     * Column 3 "Changes since you last looked": the viewer's unseen changes across the project's workstreams, newest
     * first. Reading it does not clear anything (only opening a workstream's board does).
     *
     * @param  array<string, string>  $names
     * @return array{slug:string,total:int,workstreams:int,changes:list<array<string, mixed>>}
     */
    public static function preview(Project $project, ?User $user, array $names): array
    {
        if ($project->isArchived() || $user === null) {
            return ['slug' => $project->slug, 'total' => 0, 'workstreams' => 0, 'changes' => []];
        }
        $streams = Workstream::query()->where('project_id', $project->id)->get()->keyBy('id');
        $badges = Workstreams::badges($streams->keys()->all(), $user);
        $events = Workstreams::changeEvents($streams->keys()->all(), $user)->load('ticket:id,key,title');
        $columns = collect($project->boardColumns())->pluck('label', 'key');

        return [
            'slug' => $project->slug,
            'total' => array_sum($badges),
            'workstreams' => count(array_filter($badges)),
            'changes' => $events->map(fn (TicketEvent $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'workstreamId' => $e->workstream_id,
                'workstream' => $streams[$e->workstream_id]->name ?? '',
                'actor' => Actor::label($e->actor_id, $names) ?? $e->actor_id,
                'key' => $e->ticket?->key,
                'title' => $e->ticket?->title,
                'column' => $e->type === 'moved' ? ($columns[$e->to['status'] ?? ''] ?? null) : null,
                'assignee' => $e->type === 'assigned' ? Actor::label($e->to['id'] ?? null, $names) : null,
                'fromWorkstream' => $e->type === 'workstream' ? ($e->from['name'] ?? null) : null,
                'at' => $e->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Card order (SPEC §2): Waiting input (level 3 → 1, then oldest status_set_at), Smooth sailing (latest activity
     * first), Status not set (latest activity first); archived projects last (their own section), latest activity first.
     */
    public static function order(array $a, array $b): int
    {
        $group = fn (array $p) => $p['archived'] || $p['pmStatus'] === 'archived' ? 3 : match ($p['pmStatus']) {
            'waiting' => 0, 'smooth' => 1, default => 2,
        };
        $ts = fn (?string $iso) => $iso ? strtotime($iso) : 0;
        $activity = fn (array $p) => $ts($p['lastActivityAt'] ?? $p['lastUpdatedAt']);

        return [$group($a), $group($a) === 0 ? -($a['pmLevel'] ?? 1) : 0, $group($a) === 0 ? $ts($a['pmStatusSetAt']) : -$activity($a), $a['name']]
            <=> [$group($b), $group($b) === 0 ? -($b['pmLevel'] ?? 1) : 0, $group($b) === 0 ? $ts($b['pmStatusSetAt']) : -$activity($b), $b['name']];
    }
}
