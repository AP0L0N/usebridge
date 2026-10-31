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
 * Build one per request: it caches agent names.
 */
final class TicketView
{
    /** Eager loads ticket() needs (comment rows without bodies, for counts and unread). */
    public static function with(): array
    {
        return [
            'project',
            'labels',
            'tilenReadState',
            'comments' => fn ($q) => $q->select(['id', 'ticket_id', 'author_type', 'author_id', 'is_tilen_input', 'retracted_at', 'created_at']),
        ];
    }

    /** @var array<string, string> */
    private array $names;

    private CarbonInterface $now;

    public function __construct(?array $names = null, ?CarbonInterface $now = null)
    {
        $this->names = $names ?? Actor::names();
        $this->now = $now ?? Carbon::now();
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
        $waiting = $t->isWaitingOnTilen();
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
            'waiting_since' => $t->waiting_since?->toIso8601String(),
            'waiting_age_days' => $days,
            'waiting_level' => $days === null ? null : ProjectStatus::level($days),
            'tilen_input_at' => $t->tilen_input_at?->toIso8601String(),
            'tilen_input_ack_at' => $t->tilen_input_ack_at?->toIso8601String(),
            'needs_pm_pickup' => $t->needsPmPickup(),
            'tilen_answered_ask' => $this->answeredAsk($t),
            'labels' => $t->labels->map->toView()->values()->all(),
            'comment_count' => $live->count(),
            'last_comment_at' => $live->max('created_at')?->toIso8601String(),
            'unread_for_tilen' => $unread->isNotEmpty(),
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
     * Agent comments Tilen hasn't seen (newer than ticket_read_states.last_seen_at), counted only on tickets that
     * involve him (waiting on him, or he has commented) — SPEC §4 "New reply from {agent}".
     */
    private function unread(Ticket $t, $live)
    {
        $involved = $t->isWaitingOnTilen() || $live->contains(fn ($c) => $c->author_id === Actor::TILEN);
        if (! $involved) {
            return collect();
        }
        $seen = $t->tilenReadState?->last_seen_at;

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
            'body' => $retracted ? null : $c->body,
            'retracted' => $retracted,
            'retracted_at' => $c->retracted_at?->toIso8601String(),
            'is_tilen_input' => $c->is_tilen_input,
            'input_choice' => $retracted ? null : $c->input_choice,
            // Tilen input comments: picked up once the PM acked (or moved the ticket) at/after this comment.
            'picked_up_at' => $c->is_tilen_input && $ack !== null && $ack->gte($c->created_at) ? $ack->toIso8601String() : null,
            'edited_at' => $c->edited_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
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
            'unread_for_tilen' => ['nullable', 'in:0,1,true,false'],
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
                : $q->where('assignee_id', strcasecmp($f['assignee'], Actor::TILEN) === 0 ? Actor::TILEN : $f['assignee']);
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
        if (! empty($f['unread_for_tilen'])) {
            $q->unreadForTilen();
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
     * QA M1: Tilen already answered the current ask (the PM may have acked it while the ticket stays in Waiting on
     * Tilen). False once the PM sets a new ask or moves the ticket back into Waiting on Tilen after his answer, so a
     * blank Your input form shows only for a new ask. Queries only acked tickets waiting on Tilen (few).
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
