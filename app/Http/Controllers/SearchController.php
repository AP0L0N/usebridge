<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\Workstream;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global search (Cmd/Ctrl+K in the app shell): tickets by key or title, projects and workstreams by name, in the current
 * workspace only (WorkspaceScope) and never in a deleted project or workstream. Archived items follow "Show archived":
 * hidden unless ?archived=1. Archived means what the Portfolio and the board mean: a project archived (Project::isArchived),
 * its workstreams and tickets with it, and a ticket archived on its own (archived_at).
 */
class SearchController extends Controller
{
    /** Results per group. */
    public const LIMIT = 8;

    public const QUERY_MAX = 100;

    /**
     * GET /search?q=…[&archived=1] → {data: {tickets: [{id, key, title, project_slug, project_name, archived}], projects:
     * [{id, slug, name, archived}], workstreams: [{id, name, project_slug, project_name, archived}]}}. Tickets: an exact key
     * (any case) first, then the most recently updated.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:'.self::QUERY_MAX]]);
        $q = trim((string) $request->query('q', ''));
        $archived = $request->boolean('archived');
        if ($q === '') {
            return response()->json(['data' => ['tickets' => [], 'projects' => [], 'workstreams' => []]]);
        }
        // Substring match like the board's filter (LIKE is case-insensitive on MySQL's default collation and SQLite for ASCII).
        $needle = '%'.$q.'%';

        $tickets = Ticket::query()->join('projects', 'projects.id', '=', 'tickets.project_id')->whereNull('projects.deleted_at')
            ->where(fn (Builder $w) => $w->where('tickets.key', 'like', $needle)->orWhere('tickets.title', 'like', $needle))
            ->when(! $archived, fn (Builder $w) => self::liveProjects($w)->whereNull('tickets.archived_at'))
            ->orderByRaw('case when tickets.key = ? then 0 else 1 end', [mb_strtoupper($q)])
            ->orderByDesc('tickets.updated_at')->orderBy('tickets.key')->limit(self::LIMIT)
            ->get(['tickets.id', 'tickets.key', 'tickets.title', 'tickets.archived_at', 'projects.slug as project_slug', 'projects.name as project_name', 'projects.archived_at as project_archived_at', 'projects.status as project_status']);

        $projects = Project::query()->where('name', 'like', $needle)
            ->when(! $archived, fn (Builder $w) => self::liveProjects($w))
            ->orderBy('name')->limit(self::LIMIT)->get(['id', 'slug', 'name', 'archived_at', 'status']);

        $workstreams = Workstream::query()->join('projects', 'projects.id', '=', 'workstreams.project_id')->whereNull('projects.deleted_at')
            ->where('workstreams.name', 'like', $needle)
            ->when(! $archived, fn (Builder $w) => self::liveProjects($w))
            ->orderBy('projects.name')->orderBy('workstreams.position')->limit(self::LIMIT)
            ->get(['workstreams.id', 'workstreams.name', 'projects.slug as project_slug', 'projects.name as project_name', 'projects.archived_at as project_archived_at', 'projects.status as project_status']);

        return response()->json(['data' => [
            'tickets' => $tickets->map(fn (Ticket $t) => [
                'id' => $t->id,
                'key' => $t->key,
                'title' => $t->title,
                'project_slug' => $t->getAttribute('project_slug'),
                'project_name' => $t->getAttribute('project_name'),
                'archived' => $t->archived_at !== null || self::joinedProjectArchived($t),
            ])->all(),
            'projects' => $projects->map(fn (Project $p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'name' => $p->name,
                'archived' => $p->isArchived(),
            ])->all(),
            'workstreams' => $workstreams->map(fn (Workstream $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'project_slug' => $w->getAttribute('project_slug'),
                'project_name' => $w->getAttribute('project_name'),
                'archived' => self::joinedProjectArchived($w),
            ])->all(),
        ]]);
    }

    /** Projects that are not archived (the SQL side of Project::isArchived), on `projects` itself or joined. */
    private static function liveProjects(Builder $query): Builder
    {
        return $query->whereNull('projects.archived_at')
            ->where(fn (Builder $w) => $w->whereNull('projects.status')->orWhere('projects.status', '!=', 'archived'));
    }

    /** Project::isArchived on the columns joined as project_archived_at / project_status. */
    private static function joinedProjectArchived(Ticket|Workstream $row): bool
    {
        return $row->getAttribute('project_archived_at') !== null || $row->getAttribute('project_status') === 'archived';
    }
}
