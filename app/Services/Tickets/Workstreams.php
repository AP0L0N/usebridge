<?php

namespace App\Services\Tickets;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\Workstream;
use App\Models\WorkstreamEvent;
use App\Models\WorkstreamView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Workstream rules in one place (portfolio-workstreams README §2–§5):
 * - every project has exactly one live default ("General"); a ticket without a workstream lands there, and a project
 *   without a default gets "General" before the ticket is saved.
 * - names are unique (case-insensitive) among a project's live workstreams, 1–60 characters.
 * - deleting a workstream that holds tickets (or is the default) moves them first; tickets are never deleted.
 * - add / rename / delete write workstream_events; lookups by name also match older and deleted names.
 * - badges: changes (ticket_events) in a workstream since the user last opened its board, not their own.
 */
final class Workstreams
{
    /** Ticket event types that count as a change for the badge (README §2). `moved` only when the column changed. */
    public const COUNTED = ['created', 'moved', 'commented', 'assigned', 'workstream'];

    public const BADGE_CAP = 99;

    // ---------------------------------------------------------------- read

    /** @return Collection<int, Workstream> live workstreams in board order: custom order, the default last. */
    public static function ordered(Project $project): Collection
    {
        return Workstream::query()->where('project_id', $project->id)->get()->sortBy(fn (Workstream $w) => [$w->is_default ? 1 : 0, $w->position, $w->created_at?->getTimestamp() ?? 0, $w->id])->values();
    }

    /** The project's default workstream; creates "General" (or promotes a live one) when there is none. */
    public static function ensureDefault(Project $project, ?Actor $actor = null): Workstream
    {
        $default = Workstream::query()->where('project_id', $project->id)->where('is_default', true)->first();
        if ($default !== null) {
            return $default;
        }

        return DB::transaction(function () use ($project, $actor) {
            Project::query()->withTrashed()->whereKey($project->id)->lockForUpdate()->first();
            $default = Workstream::query()->where('project_id', $project->id)->where('is_default', true)->first();
            if ($default !== null) {
                return $default;
            }
            $general = Workstream::query()->where('project_id', $project->id)->whereRaw('lower(name) = ?', [Str::lower(Workstream::DEFAULT_NAME)])->first();
            if ($general !== null) {
                $general->forceFill(['is_default' => true])->save();

                return $general;
            }

            return self::insert($project, Workstream::DEFAULT_NAME, true, $actor ?? Actor::migration(), null);
        });
    }

    /** Default workstream id of a project id (Ticket::creating for rows written outside TicketService). */
    public static function defaultIdFor(string $projectId): ?string
    {
        $project = Project::acrossWorkspaces()->withTrashed()->find($projectId);

        return $project ? self::ensureDefault($project)->id : null;
    }

    /**
     * Live workstream for an id, a current name, an older name (renamed_from / renamed events) or a deleted name
     * (followed to where its tickets went). Null when nothing matches.
     */
    public static function resolve(Project $project, string $ref): ?Workstream
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }
        $live = Workstream::query()->where('project_id', $project->id)->get();
        if ($byId = $live->firstWhere('id', $ref)) {
            return $byId;
        }
        $lower = Str::lower($ref);
        if ($byName = $live->first(fn (Workstream $w) => Str::lower($w->name) === $lower)) {
            return $byName;
        }
        if ($byOld = $live->first(fn (Workstream $w) => in_array($lower, array_map(Str::lower(...), $w->renamed_from ?? []), true))) {
            return $byOld;
        }
        // A deleted (or renamed-away) name: follow the log to the live workstream that took its tickets.
        $event = WorkstreamEvent::query()->where('project_id', $project->id)
            ->where(fn (Builder $q) => $q->where('workstream_id', $ref)->orWhereRaw('lower(from_name) = ?', [$lower]))
            ->orderByDesc('id')->first();
        if ($event === null) {
            return null;
        }
        $seen = [];
        $id = $event->merged_into_id ?? $event->workstream_id;
        while ($id !== null && ! isset($seen[$id])) {
            $seen[$id] = true;
            if ($found = $live->firstWhere('id', $id)) {
                return $found;
            }
            $id = WorkstreamEvent::query()->where('workstream_id', $id)->whereNotNull('merged_into_id')->orderByDesc('id')->value('merged_into_id');
        }

        return null;
    }

    /**
     * The workstream a ticket write asks for: null/empty = the default; an unknown name creates it when $create (agents
     * file into a new workstream when none fits); an unknown id is a 422.
     */
    public static function forTicket(Project $project, ?string $ref, Actor $actor, bool $create = true, string $field = 'workstream'): Workstream
    {
        $ref = TicketService::clean($ref);
        if ($ref === null) {
            return self::ensureDefault($project, $actor);
        }
        if ($found = self::resolve($project, $ref)) {
            return $found;
        }
        if (! $create || str_starts_with($ref, 'ws-')) {
            throw ValidationException::withMessages([$field => "Unknown workstream \"$ref\" in {$project->name} (list_workstreams)."]);
        }
        self::ensureDefault($project, $actor);

        return self::create($project, $ref, $actor);
    }

    /** @return array{id:string,project_id:string,name:string,is_default:bool,position:int,renamed_from:list<string>} */
    public static function present(Workstream $w): array
    {
        return [
            'id' => $w->id,
            'project_id' => $w->project_id,
            'name' => $w->name,
            'is_default' => $w->is_default,
            'position' => $w->position,
            'renamed_from' => array_values($w->renamed_from ?? []),
        ];
    }

    /** A list filter's workstream: a ws-… id anywhere in the workspace, a name only within $project. 422 when unknown. */
    public static function filterId(?Project $project, string $ref): string
    {
        $ref = trim($ref);
        $found = $project !== null ? self::resolve($project, $ref) : Workstream::query()->find($ref);
        if ($found === null) {
            throw ValidationException::withMessages(['workstream' => $project === null && ! str_starts_with($ref, 'ws-')
                ? 'Filter by workstream name together with a project (or pass the workstream id).'
                : "Unknown workstream \"$ref\"."]);
        }

        return $found->id;
    }

    // ---------------------------------------------------------------- writes

    public static function create(Project $project, string $name, Actor $actor, ?User $user = null): Workstream
    {
        self::ensureOpen($project);
        $name = self::validName($project, $name);

        return DB::transaction(fn () => self::insert($project, $name, false, $actor, $user));
    }

    public static function rename(Workstream $ws, string $name, Actor $actor, ?User $user = null): Workstream
    {
        $project = $ws->project;
        self::ensureOpen($project);
        $name = self::validName($project, $name, $ws->id);
        if ($name === $ws->name) {
            return $ws;
        }

        return DB::transaction(function () use ($ws, $name, $actor, $user) {
            $old = $ws->name;
            $ws->forceFill(['name' => $name, 'renamed_from' => array_values(array_unique([...($ws->renamed_from ?? []), $old]))])->save();
            self::log($ws, 'renamed', $actor, $user, ['from_name' => $old, 'to_name' => $name]);

            return $ws->refresh();
        });
    }

    /**
     * Delete $ws. With tickets, or when it is the default, the tickets (and the default flag) go to $targetId or to a
     * new workstream $newName first. The only workstream needs a new name. Soft delete: history keeps the name.
     *
     * @return array{deleted: Workstream, target: ?Workstream, moved: int}
     */
    public static function delete(Workstream $ws, ?string $targetId, ?string $newName, Actor $actor, ?User $user = null): array
    {
        $project = $ws->project;
        self::ensureOpen($project);
        $count = Ticket::query()->where('workstream_id', $ws->id)->count();
        $others = Workstream::query()->where('project_id', $project->id)->whereKeyNot($ws->id)->get();
        $needsTarget = $count > 0 || $ws->is_default || $others->isEmpty();
        $targetId = TicketService::clean($targetId);
        $newName = TicketService::clean($newName);

        $target = null;
        if ($needsTarget) {
            if ($targetId !== null) {
                $target = $others->firstWhere('id', $targetId)
                    ?? throw ValidationException::withMessages(['target' => 'Choose another workstream of this project.']);
            } elseif ($newName === null) {
                throw ValidationException::withMessages([$others->isEmpty() ? 'new_name' : 'target' => $others->isEmpty()
                    ? "\"{$ws->name}\" is the only workstream. Name a workstream to move its tickets to."
                    : "Choose where the tickets of \"{$ws->name}\" go first."]);
            } else {
                $newName = self::validName($project, $newName, $ws->id);
            }
        }

        return DB::transaction(function () use ($ws, $project, $target, $newName, $needsTarget, $count, $actor, $user) {
            if ($needsTarget && $target === null) {
                $target = self::insert($project, $newName, false, $actor, $user);
            }
            $moved = $target ? self::moveAll($ws, $target, $actor) : 0;
            if ($ws->is_default && $target) {
                $ws->forceFill(['is_default' => false])->save();
                $target->forceFill(['is_default' => true])->save();
            }
            self::log($ws, 'deleted', $actor, $user, [
                'from_name' => $ws->name, 'to_name' => $target?->name, 'merged_into_id' => $target?->id, 'ticket_count' => $count,
            ]);
            $ws->delete();
            Project::touchActivity($project->id);

            return ['deleted' => $ws, 'target' => $target?->refresh(), 'moved' => $moved];
        });
    }

    /** "Move all tickets to…": every ticket of $from goes to $to (each logged on the ticket); $from stays. */
    public static function moveAllTickets(Workstream $from, Workstream $to, Actor $actor): int
    {
        self::ensureOpen($from->project);
        if ($from->project_id !== $to->project_id || $from->id === $to->id) {
            throw ValidationException::withMessages(['target' => 'Choose another workstream of this project.']);
        }

        return DB::transaction(fn () => self::moveAll($from, $to, $actor));
    }

    public static function makeDefault(Workstream $ws): Workstream
    {
        self::ensureOpen($ws->project);
        DB::transaction(function () use ($ws) {
            Workstream::query()->where('project_id', $ws->project_id)->whereKeyNot($ws->id)->update(['is_default' => false]);
            $ws->forceFill(['is_default' => true])->save();
        });

        return $ws->refresh();
    }

    /** Move up / down in the custom order (the default stays pinned last on the board). */
    public static function reorder(Workstream $ws, string $direction): void
    {
        self::ensureOpen($ws->project);
        $list = self::ordered($ws->project)->reject(fn (Workstream $w) => $w->is_default)->values();
        $i = $list->search(fn (Workstream $w) => $w->id === $ws->id);
        $j = $direction === 'up' ? $i - 1 : $i + 1;
        if ($i === false || $j < 0 || $j >= $list->count()) {
            return;
        }
        $ids = $list->pluck('id')->all();
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        foreach ($ids as $pos => $id) {
            Workstream::query()->whereKey($id)->update(['position' => $pos]);
        }
    }

    /** One ticket into another workstream of its project (ticket event `workstream`, counted on the target). */
    public static function moveTicket(Ticket $ticket, Workstream $to, Actor $actor): void
    {
        if ($ticket->workstream_id === $to->id) {
            return;
        }
        $from = Workstream::withTrashed()->find($ticket->workstream_id);
        $ticket->workstream_id = $to->id;
        $ticket->save();
        app(TicketService::class)->event($ticket, 'workstream',
            ['workstream_id' => $from?->id, 'name' => $from?->name],
            ['workstream_id' => $to->id, 'name' => $to->name], $actor);
    }

    /** Opening a workstream's board: its badge clears for this user right away. */
    public static function markViewed(Workstream $ws, User $user): void
    {
        WorkstreamView::query()->updateOrCreate(['user_id' => $user->id, 'workstream_id' => $ws->id], ['last_viewed_at' => Carbon::now()]);
    }

    // ---------------------------------------------------------------- badges

    /**
     * Unseen changes per workstream id for $user (README §2): counted ticket events after the user's last view (else
     * after the workstream was created), not by the user. Uncapped; the UI shows 99+.
     *
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    public static function badges(array $ids, ?User $user): array
    {
        if ($user === null || $ids === []) {
            return [];
        }

        return self::changes($ids, $user)
            ->selectRaw('ticket_events.workstream_id as ws, count(*) as n')
            ->groupBy('ticket_events.workstream_id')
            ->pluck('n', 'ws')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * The unseen change events themselves, newest first (Preview: "Changes since you last looked").
     *
     * @param  list<string>  $ids
     * @return Collection<int, TicketEvent>
     */
    public static function changeEvents(array $ids, ?User $user, int $limit = 30): Collection
    {
        if ($user === null || $ids === []) {
            return collect();
        }

        return self::changes($ids, $user)->select('ticket_events.*')
            ->orderByDesc('ticket_events.created_at')->orderByDesc('ticket_events.id')->limit($limit)->get();
    }

    /** @param list<string> $ids */
    private static function changes(array $ids, User $user): Builder
    {
        $me = HumanActors::idFor($user);

        return TicketEvent::query()
            ->join('workstreams as w', 'w.id', '=', 'ticket_events.workstream_id')
            ->leftJoin('workstream_views as v', fn ($j) => $j->on('v.workstream_id', '=', 'w.id')->where('v.user_id', '=', $user->id))
            ->whereIn('ticket_events.workstream_id', $ids)
            ->whereIn('ticket_events.type', self::COUNTED)
            ->where('ticket_events.actor_id', '!=', $me)
            ->whereRaw('ticket_events.created_at > coalesce(v.last_viewed_at, w.created_at)')
            ->where(fn (Builder $q) => $q->where('ticket_events.type', '!=', 'moved')->orWhereColumn('ticket_events.from->status', '!=', 'ticket_events.to->status'));
    }

    // ---------------------------------------------------------------- helpers

    /** Trimmed name, 1–60 characters, unique (case-insensitive) among the project's live workstreams. */
    public static function validName(Project $project, string $name, ?string $except = null, string $field = 'name'): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            throw ValidationException::withMessages([$field => 'Give the workstream a name.']);
        }
        if (mb_strlen($name) > Workstream::NAME_MAX) {
            throw ValidationException::withMessages([$field => 'Keep it to '.Workstream::NAME_MAX.' characters.']);
        }
        $taken = Workstream::query()->where('project_id', $project->id)->when($except, fn ($q) => $q->whereKeyNot($except))
            ->whereRaw('lower(name) = ?', [Str::lower($name)])->exists();
        if ($taken) {
            throw ValidationException::withMessages([$field => "{$project->name} already has a workstream called \"$name\"."]);
        }

        return $name;
    }

    private static function ensureOpen(?Project $project): void
    {
        abort_if($project === null || $project->trashed(), 404);
        abort_if($project->isArchived(), 409, "Project {$project->name} is archived; unarchive it to add, rename or move workstreams.");
    }

    private static function insert(Project $project, string $name, bool $default, Actor $actor, ?User $user): Workstream
    {
        $ws = new Workstream;
        $ws->forceFill([
            'id' => 'ws-'.Str::lower((string) Str::ulid()),
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'name' => $name,
            'position' => (int) Workstream::query()->where('project_id', $project->id)->max('position') + 1,
            'is_default' => $default,
            'created_by' => $actor->id,
        ])->save();
        self::log($ws, 'created', $actor, $user, ['to_name' => $name]);

        return $ws;
    }

    private static function moveAll(Workstream $from, Workstream $to, Actor $actor): int
    {
        $tickets = Ticket::query()->where('workstream_id', $from->id)->get();
        $service = app(TicketService::class);
        foreach ($tickets as $ticket) {
            $ticket->workstream_id = $to->id;
            $ticket->save();
            $service->event($ticket, 'workstream',
                ['workstream_id' => $from->id, 'name' => $from->name],
                ['workstream_id' => $to->id, 'name' => $to->name], $actor);
        }
        if ($tickets->isNotEmpty()) {
            Project::touchActivity($from->project_id);
        }

        return $tickets->count();
    }

    /** @param array<string, mixed> $fields */
    private static function log(Workstream $ws, string $type, Actor $actor, ?User $user, array $fields): void
    {
        $e = new WorkstreamEvent;
        $e->forceFill([
            'workspace_id' => $ws->workspace_id,
            'project_id' => $ws->project_id,
            'workstream_id' => $ws->id,
            'type' => $type,
            'actor_user_id' => $user?->id ?? ($actor->isHuman() ? self::userIdOf($actor->id) : null),
            'actor_agent_id' => $actor->type === 'agent' ? $actor->id : null,
            ...$fields,
        ])->save();
    }

    /** users.id behind a human actor id ('user:<id>', or the legacy 'tilen' = the workspace owner). */
    private static function userIdOf(string $actorId): ?int
    {
        if (str_starts_with($actorId, HumanActors::USER_PREFIX)) {
            return (int) substr($actorId, strlen(HumanActors::USER_PREFIX));
        }

        return $actorId === Actor::TILEN ? HumanActors::workspace()?->owner_user_id : null;
    }
}
