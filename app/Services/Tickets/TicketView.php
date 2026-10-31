<?php

namespace App\Services\Tickets;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use App\Services\ProjectStatus;
use App\Support\BoardColumns;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * JSON shapes for tickets, comments and events (API responses and, from the UI run on, Inertia props).
 * Build one per request: it caches agent and human names.
 *
 * "Owner input" fields keep their old names as compatibility aliases (waiting_on_tilen, tilen_input_at,
 * tilen_input_ack_at, tilen_answered_ask, unread_for_tilen, is_tilen_input) next to the neutral ones (waiting_on_owner,
 * owner_input_at, owner_input_ack_at, owner_answered_ask, unread_for_me, is_owner_input) with the same values.
 * unread_for_me is for the human viewing: the signed-in web user, or the workspace owner over the API / MCP.
 */
final class TicketView
{
    /** Eager loads ticket() needs (comment rows without bodies, for counts and unread). */
    public static function with(): array
    {
        return [
            'project',
            'labels',
            'viewerReadState',
            'comments' => fn ($q) => $q->select(['id', 'ticket_id', 'author_type', 'author_id', 'is_tilen_input', 'retracted_at', 'created_at']),
        ];
    }

    /** @var array<string, string> */
    private array $names;

    private CarbonInterface $now;

    /** Actor id of the human reading (HumanActors::viewerId). */
    private string $viewer;

    public function __construct(?array $names = null, ?CarbonInterface $now = null)
    {
        $this->names = $names ?? Actor::names();
        $this->now = $now ?? Carbon::now();
        $this->viewer = HumanActors::viewerId();
    }

    public function name(?string $id): ?string
    {
        return Actor::label($id, $this->names);
    }

    public static function url(Project $project, string $key): string
    {
        return rtrim((string) config('bridge.public_url'), '/').'/projects/'.$project->slug.'/tickets/'.$key;
    }

    public function ticket(Ticket $t): array
    {
        $t->loadMissing(self::with());
        $waiting = $t->isWaitingOnOwner();
        $days = $waiting && $t->waiting_since ? ProjectStatus::ageDays($t->waiting_since, $this->now) : null;
        $live = $t->comments->whereNull('retracted_at');
        $unread = $this->unread($t, $live);
        $latestUnread = $unread->last();

        return [
            'id' => $t->id,
            'key' => $t->key,
            'project_id' => $t->project_id,
            'pm_agent_id' => $t->project->owner_agent_id,
            'title' => $t->title,
            'description' => $t->description,
            'status' => $t->status,
            'status_label' => BoardColumns::label($t->status),
            'position' => $t->position,
            'assignee_type' => $t->assignee_type,
            'assignee_id' => $t->assignee_id,
            'assignee_name' => $this->name($t->assignee_id),
            'priority' => $t->priority,
            'due_on' => $t->due_on?->format('Y-m-d'),
            'blocked' => $t->blocked_reason !== null,
            'blocked_reason' => $t->blocked_reason,
            'ask' => $t->ask,
            'ask_context' => $t->ask_context,
            'ask_options' => $t->ask_options,
            'waiting_on_tilen' => $waiting,
            'waiting_on_owner' => $waiting,
            'waiting_since' => $t->waiting_since?->toIso8601String(),
            'waiting_age_days' => $days,
            'waiting_level' => $days === null ? null : ProjectStatus::level($days),
            'tilen_input_at' => $inputAt = $t->tilen_input_at?->toIso8601String(),
            'tilen_input_ack_at' => $ackAt = $t->tilen_input_ack_at?->toIso8601String(),
            'owner_input_at' => $inputAt,
            'owner_input_ack_at' => $ackAt,
            'needs_pm_pickup' => $t->needsPmPickup(),
            'tilen_answered_ask' => $answered = $this->answeredAsk($t),
            'owner_answered_ask' => $answered,
            'labels' => $t->labels->map->toView()->values()->all(),
            'comment_count' => $live->count(),
            'last_comment_at' => $live->max('created_at')?->toIso8601String(),
            // Card "via MCP · Builder · 12m": the newest live comment's author and time (null when no comments).
            'latest_comment' => ($latest = $live->sortBy('created_at')->last()) ? [
                'author_id' => $latest->author_id,
                'author_name' => $this->name($latest->author_id),
                'author_type' => $latest->author_type,
                // api (REST token) | mcp | ui (web); go-live N2. Older MCP comments were recorded as api.
                'source' => $this->commentSource($t->id, $latest->id),
                'created_at' => $latest->created_at?->toIso8601String(),
            ] : null,
            'unread_for_tilen' => $unread->isNotEmpty(),
            'unread_for_me' => $unread->isNotEmpty(),
            'latest_unread_author' => $latestUnread ? ['id' => $latestUnread->author_id, 'name' => $this->name($latestUnread->author_id)] : null,
            'created_by' => $t->created_by,
            'created_by_type' => $t->created_by_type,
            'created_by_name' => $this->name($t->created_by),
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
            'done_at' => $t->done_at?->toIso8601String(),
            'archived' => $t->archived_at !== null,
            'archived_at' => $t->archived_at?->toIso8601String(),
            'source_type' => $t->source_type,
            'source_id' => $t->source_id,
            'url' => self::url($t->project, $t->key),
        ];
    }

    /**
     * Agent comments the viewer hasn't seen (newer than their ticket_read_states.last_seen_at), counted only on tickets
     * that involve them (waiting on the owner, or they have commented) — SPEC §4 "New reply from {agent}".
     */
    private function unread(Ticket $t, $live)
    {
        $involved = $t->isWaitingOnOwner() || $live->contains(fn ($c) => $c->author_id === $this->viewer);
        if (! $involved) {
            return collect();
        }
        $seen = $t->viewerReadState?->last_seen_at;

        return $live->where('author_type', 'agent')
            ->filter(fn ($c) => $seen === null || $c->created_at->gt($seen))
            ->sortBy('id')->values();
    }

    /** Ticket detail: ticket + comments (oldest first; retracted as {retracted:true, body:null}) + newest 50 events. */
    public function detail(Ticket $t): array
    {
        $comments = TicketComment::query()->where('ticket_id', $t->id)->orderBy('id')->get();
        $events = TicketEvent::query()->where('ticket_id', $t->id)->orderByDesc('id')->limit(50)->get();

        return [
            ...$this->ticket($t),
            'created_source' => $this->createdSource($t),
            'comments' => $comments->map(fn ($c) => $this->comment($c, $t))->all(),
            'events' => $events->map(fn ($e) => $this->event($e))->all(),
        ];
    }

    public function comment(TicketComment $c, ?Ticket $ticket = null): array
    {
        $retracted = $c->retracted_at !== null;
        $ticket ??= $c->ticket;
        $ack = $ticket?->tilen_input_ack_at;

        return [
            'id' => $c->id,
            'cursor' => (string) $c->id,
            'ticket_id' => $c->ticket_id,
            'author_type' => $c->author_type,
            'author_id' => $c->author_id,
            'author_name' => $this->name($c->author_id),
            'source' => $this->commentSource($c->ticket_id, $c->id),
            'body' => $retracted ? null : $c->body,
            'retracted' => $retracted,
            'retracted_at' => $c->retracted_at?->toIso8601String(),
            'is_tilen_input' => $c->is_tilen_input,
            'is_owner_input' => $c->is_tilen_input,
            'input_choice' => $retracted ? null : $c->input_choice,
            // Owner input comments: picked up once the PM acked (or moved the ticket) at/after this comment.
            'picked_up_at' => $c->is_tilen_input && $ack !== null && $ack->gte($c->created_at) ? $ack->toIso8601String() : null,
            'edited_at' => $c->edited_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    /** @var array<string, array<int, string>> ticket id => [comment id => source] */
    private array $commentSources = [];

    /** One query for the comment sources of many tickets (board cards); tickets without comments cache []. */
    public function preloadCommentSources(array $ticketIds): void
    {
        $ticketIds = array_values(array_diff($ticketIds, array_keys($this->commentSources)));
        if ($ticketIds === []) {
            return;
        }
        foreach ($ticketIds as $id) {
            $this->commentSources[$id] = [];
        }
        foreach (array_chunk($ticketIds, 500) as $chunk) {
            TicketEvent::query()->whereIn('ticket_id', $chunk)->where('type', 'commented')->orderBy('id')
                ->get(['ticket_id', 'to', 'source'])
                ->each(function (TicketEvent $e) {
                    if (isset($e->to['comment_id'])) {
                        $this->commentSources[$e->ticket_id][(int) $e->to['comment_id']] = (string) $e->source;
                    }
                });
        }
    }

    /** Where a comment was written: the source of its 'commented' event (api|mcp|ui), null when unknown. */
    private function commentSource(string $ticketId, int|string $commentId): ?string
    {
        $this->commentSources[$ticketId] ??= TicketEvent::query()->where('ticket_id', $ticketId)->where('type', 'commented')
            ->orderBy('id')->get(['to', 'source'])
            ->filter(fn (TicketEvent $e) => isset($e->to['comment_id']))
            ->mapWithKeys(fn (TicketEvent $e) => [(int) $e->to['comment_id'] => (string) $e->source])->all();

        return $this->commentSources[$ticketId][(int) $commentId] ?? null;
    }

    /** Where the ticket was created: the source of its 'created' event (api|mcp|ui), null when unknown. */
    private function createdSource(Ticket $t): ?string
    {
        return TicketEvent::query()->where('ticket_id', $t->id)->where('type', 'created')->orderBy('id')->value('source');
    }

    public function event(TicketEvent $e): array
    {
        return [
            'id' => $e->id,
            'ticket_id' => $e->ticket_id,
            'project_id' => $e->project_id,
            'type' => $e->type,
            'from' => $e->from,
            'to' => $e->to,
            'actor_id' => $e->actor_id,
            'actor_type' => $e->actor_type,
            'actor_name' => $this->name($e->actor_id),
            'source' => $e->source,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }

    /** ISO time from a query string, in the app timezone the DB timestamps are stored in. */
    public static function time(string $iso): Carbon
    {
        return Carbon::parse($iso)->setTimezone((string) config('app.timezone'));
    }

    /** Validation of the list filters (GET /api/tickets, /api/projects/{id}/tickets and MCP list_tickets). */
    public static function filterRules(): array
    {
        return [
            'project_id' => ['nullable', 'string'],
            'status' => ['nullable', 'string', function ($attr, $value, $fail) {
                foreach (explode(',', (string) $value) as $s) {
                    if (! in_array(trim($s), BoardColumns::keys(), true)) {
                        $fail('status must be one of '.implode(', ', BoardColumns::keys()).' (comma-separated).');
                    }
                }
            }],
            'assignee' => ['nullable', 'string', 'max:120'],
            'label' => ['nullable', 'string', 'max:24'],
            'q' => ['nullable', 'string', 'max:120'],
            'needs_pm_pickup' => ['nullable', 'in:0,1,true,false'],
            'unread_for_me' => ['nullable', 'in:0,1,true,false'],
            'unread_for_owner' => ['nullable', 'in:0,1,true,false'],
            'unread_for_tilen' => ['nullable', 'in:0,1,true,false'], // compat alias
            'blocked' => ['nullable', 'in:0,1,true,false'],
            'include_archived' => ['nullable', 'in:0,1,true,false'],
            'updated_since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    /** Shared list filters (GET /api/tickets and /api/projects/{id}/tickets, MCP list_tickets). */
    public static function filter(Builder $q, array $f): Builder
    {
        if (! empty($f['project_id'])) {
            $q->where('project_id', $f['project_id']);
        }
        if (! empty($f['status'])) {
            $q->whereIn('status', array_map('trim', explode(',', $f['status'])));
        }
        if (! empty($f['assignee'])) {
            $f['assignee'] === 'none'
                ? $q->whereNull('assignee_id')
                : $q->where('assignee_id', HumanActors::ownerAlias($f['assignee']) ?? $f['assignee']);
        }
        if (! empty($f['label'])) {
            $q->whereHas('labels', fn ($l) => $l->whereRaw('LOWER(name) = ?', [mb_strtolower($f['label'])]));
        }
        if (! empty($f['q'])) {
            // Title or key substring (LIKE is case-insensitive on MySQL's default collation and SQLite for ASCII).
            $needle = '%'.trim($f['q']).'%';
            $q->where(fn ($w) => $w->where('title', 'like', $needle)->orWhere('key', 'like', $needle));
        }
        if (! empty($f['needs_pm_pickup'])) {
            $q->needsPmPickup();
        }
        if (! empty($f['unread_for_me']) || ! empty($f['unread_for_owner']) || ! empty($f['unread_for_tilen'])) {
            $q->unreadFor();
        }
        if (! empty($f['blocked'])) {
            $q->whereNotNull('blocked_reason');
        }
        if (! empty($f['updated_since'])) {
            $q->where('updated_at', '>=', self::time($f['updated_since']));
        }
        if (empty($f['include_archived'])) {
            $q->whereNull('archived_at');
        }

        return $q->orderBy('project_id')->orderByRaw(BoardColumns::orderSql())->orderBy('position')->orderBy('key');
    }

    /**
     * QA M1: the current ask is already answered (the PM may have acked it while the ticket stays in the waiting
     * column). False once the PM sets a new ask or moves the ticket back into the waiting column after the answer, so a
     * blank Your input form shows only for a new ask. Queries only acked tickets in the waiting column (few).
     */
    public function answeredAsk(Ticket $t): bool
    {
        if ($t->status !== BoardColumns::WAITING_TILEN || $t->tilen_input_at === null) {
            return false;
        }
        if ($t->needsPmPickup()) {
            return true;
        }

        return ! TicketEvent::query()->where('ticket_id', $t->id)->whereIn('type', ['ask_set', 'moved'])
            ->where('created_at', '>', $t->tilen_input_at)->get(['type', 'from', 'to'])
            ->contains(fn (TicketEvent $e) => $e->type === 'ask_set'
                || (($e->to['status'] ?? null) === BoardColumns::WAITING_TILEN && ($e->from['status'] ?? null) !== BoardColumns::WAITING_TILEN));
    }
}
