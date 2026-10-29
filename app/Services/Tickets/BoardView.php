<?php

namespace App\Services\Tickets;

use App\Models\Project;
use App\Models\ProjectReadState;
use App\Models\Ticket;
use App\Support\BoardColumns;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The project board payload (GET /api/projects/{id}/board; the UI run reuses it as Inertia props).
 * Waiting on Tilen is ordered oldest waiting_since first; every other column by position.
 * Done shows tickets done since `done_since` (default 7 days) plus done_older_count.
 */
final class BoardView
{
    public const DONE_DAYS = 7;

    public function __construct(private readonly TicketView $view = new TicketView) {}

    public function project(Project $p): array
    {
        $seen = ProjectReadState::query()->where('user', Actor::TILEN)->where('project_id', $p->id)->first();

        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'pm_agent_id' => $p->owner_agent_id,
            'pm_name' => $this->view->name($p->owner_agent_id),
            'ticket_prefix' => $p->ticket_prefix,
            'status' => $p->status,
            'status_label' => Project::statusLabel($p->status),
            'status_note' => $p->status_note,
            'status_set_by' => $p->status_set_by,
            'status_set_by_name' => $this->view->name($p->status_set_by),
            'status_set_at' => $p->status_set_at?->toIso8601String(),
            'last_activity_at' => $p->last_activity_at?->toIso8601String(),
            'archived' => $p->isArchived(),
            // For the "status changed" toast: set after Tilen last saw it (and not by Tilen himself).
            'status_unseen_by_tilen' => $p->status_set_at !== null && $p->status_set_by !== Actor::TILEN
                && ($seen?->status_seen_at === null || $p->status_set_at->gt($seen->status_seen_at)),
        ];
    }

    public function build(Project $p, ?CarbonInterface $doneSince = null, bool $includeArchived = false): array
    {
        $doneSince ??= Carbon::now()->subDays(self::DONE_DAYS);
        $q = Ticket::query()->where('project_id', $p->id)->with(TicketView::with());
        if (! $includeArchived) {
            $q->whereNull('archived_at');
        }
        $tickets = $q->where(fn ($w) => $w->where('status', '!=', BoardColumns::DONE)->orWhere('done_at', '>=', $doneSince))
            ->orderBy('position')->orderBy('key')->get();
        $doneOlder = Ticket::query()->where('project_id', $p->id)->where('status', BoardColumns::DONE)
            ->when(! $includeArchived, fn ($w) => $w->whereNull('archived_at'))
            ->where(fn ($w) => $w->whereNull('done_at')->orWhere('done_at', '<', $doneSince))->count();

        $columns = [];
        foreach (BoardColumns::all() as $col) {
            $in = $tickets->where('status', $col['key']);
            $in = $col['key'] === BoardColumns::WAITING_TILEN
                ? $in->sortBy(fn (Ticket $t) => [$t->waiting_since?->getTimestamp() ?? 0, $t->key])
                : $in->sortBy(fn (Ticket $t) => [$t->archived_at === null ? 0 : 1, $t->position, $t->key]);
            $columns[] = [
                ...$col,
                'count' => $in->count(),
                'tickets' => $in->values()->map(fn (Ticket $t) => $this->view->ticket($t))->all(),
            ];
        }

        return [
            'project' => $this->project($p),
            'columns' => $columns,
            'done_since' => $doneSince->toIso8601String(),
            'done_older_count' => $doneOlder,
        ];
    }
}
