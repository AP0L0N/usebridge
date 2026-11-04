<?php

namespace App\Services\Tickets;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Services\ProjectStatus;
use App\Support\BoardColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * r4 "Questions for you": a project's open asks = live (not archived) tickets in the waiting column (waiting_tilen)
 * with an ask. No table of its own: the ask lives on the ticket, the answer is an owner input comment
 * (TicketService::comment via the web comment route), and the ticket leaves the list when the PM moves it out of
 * the waiting column (or archives it). An answered ask stays listed (owner_answered_ask, needs_pm_pickup) until then.
 * Shared by the portfolio / board / project payloads (count), the board's openAsks prop, GET /api/projects/{id}/asks
 * and MCP list_open_asks (list).
 */
final class OpenAsks
{
    /** Attribute name of the count (withCount alias, payload key). */
    public const COUNT = 'open_asks_count';

    /** Constrain a ticket query to open asks. */
    public static function scope(Builder $q): Builder
    {
        return $q->where('status', BoardColumns::WAITING_TILEN)->whereNull('archived_at')
            ->whereNotNull('ask')->where('ask', '!=', '');
    }

    /** Eager count for project lists (no N+1): Project::query()->withCount(OpenAsks::withCount()). */
    public static function withCount(): array
    {
        return ['tickets as '.self::COUNT => fn (Builder $q) => self::scope($q)];
    }

    /** The count from withCount(), else one query. */
    public static function count(Project $project): int
    {
        $counted = $project->getAttribute(self::COUNT);

        return $counted !== null ? (int) $counted : self::scope(Ticket::query()->where('project_id', $project->id))->count();
    }

    /**
     * The lean list, oldest waiting_since first (as the waiting column). Two queries for the tickets and their asking
     * events, plus one per answered-and-acked ticket (TicketView::answeredAsk).
     *
     * @return list<array<string, mixed>>
     */
    public static function list(Project $project, ?TicketView $view = null): array
    {
        $view ??= new TicketView;
        $tickets = self::scope(Ticket::query()->where('project_id', $project->id))
            ->orderBy('waiting_since')->orderBy('key')->get()
            ->each(fn (Ticket $t) => $t->setRelation('project', $project));
        $askers = self::askers($tickets);

        return $tickets->map(fn (Ticket $t) => self::item($t, $askers[$t->id] ?? null, $view))->values()->all();
    }

    /** @return array<string, mixed> */
    private static function item(Ticket $t, ?TicketEvent $asked, TicketView $view): array
    {
        $days = $t->waiting_since ? ProjectStatus::ageDays($t->waiting_since) : null;
        // Who asked: the actor of the latest event that put the ask in front of the owner (falls back to the creator).
        $by = $asked?->actor_id ?? $t->created_by;

        return [
            'id' => $t->id,
            'key' => $t->key,
            'project_id' => $t->project_id,
            'title' => $t->title,
            'ask' => $t->ask,
            'ask_context' => $t->ask_context,
            'ask_options' => $t->ask_options,
            'asked_by' => $by,
            'asked_by_name' => $view->name($by),
            'asked_by_type' => $asked?->actor_type ?? $t->created_by_type,
            // api | mcp | ui (the UI's viaLabel(source, type === 'agent')); null when the creator is the fallback.
            'asked_by_source' => $asked?->source,
            'asked_at' => $t->waiting_since?->toIso8601String(),
            'waiting_age_days' => $days,
            'waiting_level' => $days === null ? null : ProjectStatus::level($days),
            'owner_input_at' => $t->tilen_input_at?->toIso8601String(),
            'needs_pm_pickup' => $t->needsPmPickup(),
            'owner_answered_ask' => $view->answeredAsk($t),
            'url' => TicketView::url($t->project, $t->key),
        ];
    }

    /**
     * Per ticket, its latest asking event: created in the waiting column, moved into it from another column, or
     * ask_set (TicketService logs ask_set before moved, both with the same actor).
     *
     * @return array<string, TicketEvent>
     */
    private static function askers(Collection $tickets): array
    {
        if ($tickets->isEmpty()) {
            return [];
        }

        return TicketEvent::query()->whereIn('ticket_id', $tickets->modelKeys())->whereIn('type', ['created', 'moved', 'ask_set'])
            ->orderBy('id')->get(['id', 'ticket_id', 'type', 'from', 'to', 'actor_id', 'actor_type', 'source'])
            ->filter(fn (TicketEvent $e) => $e->type === 'ask_set'
                || (($e->to['status'] ?? null) === BoardColumns::WAITING_TILEN && ($e->from['status'] ?? null) !== BoardColumns::WAITING_TILEN))
            ->keyBy('ticket_id')->all();
    }
}
