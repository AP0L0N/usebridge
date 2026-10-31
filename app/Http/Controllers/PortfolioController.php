<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectStatusEvent;
use App\Services\ProjectStatus;
use App\Services\Tickets\Actor;
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
            ->get();
        // Where the current status came from: the latest status event's source (api = REST agent token, mcp = the MCP server, ui = the web app).
        $sources = ProjectStatusEvent::query()->whereIn('project_id', $projects->modelKeys())
            ->orderByDesc('created_at')->orderByDesc('id')->get(['project_id', 'source'])
            ->unique('project_id')->pluck('source', 'project_id');
        $projects = $projects
            ->map(fn (Project $p) => [...ProjectStatus::summary($p, null, $names), 'columnLabels' => $p->column_labels ?? (object) [], 'pmStatusSource' => $p->status_set_at ? ($sources[$p->id] ?? null) : null])
            ->sort(self::order(...))
            ->values();

        return Inertia::render('Portfolio', [
            'projects' => $projects,
            'now' => now()->toIso8601String(),
            // ?ready={slug} after onboarding: banner + connect card (null otherwise).
            'welcome' => ReadyBanner::props($request),
        ]);
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
