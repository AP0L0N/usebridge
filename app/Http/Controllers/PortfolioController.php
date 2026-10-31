<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProjectStatus;
use App\Services\Tickets\Actor;
use App\Support\BoardColumns;
use Inertia\Inertia;
use Inertia\Response;

class PortfolioController extends Controller
{
    public function __invoke(): Response
    {
        $names = Actor::names();
        $projects = Project::query()
            ->with(['statusUpdates', 'requiredInputs', 'questions', 'tickets' => fn ($q) => $q->select(['id', 'project_id', 'status', 'done_at', 'archived_at'])])
            ->get()
            ->map(fn (Project $p) => [...ProjectStatus::summary($p, null, $names), 'columnLabels' => $p->column_labels ?? (object) []])
            ->sort(self::order(...))
            ->values();

        return Inertia::render('Portfolio', [
            'projects' => $projects,
            'now' => now()->toIso8601String(),
            // The workspace's default column names (each card prefers its project's own columnLabels).
            'columns' => array_map(fn (array $c) => ['key' => $c['key'], 'label' => $c['label']], BoardColumns::all()),
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
