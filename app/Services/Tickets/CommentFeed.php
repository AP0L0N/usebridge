<?php

namespace App\Services\Tickets;

use App\Models\TicketComment;
use App\Support\BoardColumns;

/**
 * Captain's answers relay (GET /api/ticket-comments/feed): comments in id order after a cursor, with the ticket and
 * project attached so a relay message needs no further lookups. Retracted comments are left out.
 *
 * @param  array{after?:?int, since?:?string, author?:?string, inputs_only?:?bool, project_id?:?string, limit?:?int}  $f
 */
final class CommentFeed
{
    public const DEFAULT_LIMIT = 50;

    public function __construct(private readonly TicketView $view = new TicketView) {}

    public function page(array $f): array
    {
        $limit = (int) ($f['limit'] ?? self::DEFAULT_LIMIT);
        $q = TicketComment::query()->whereNull('retracted_at')->with('ticket.project')->orderBy('id');

        if (isset($f['after']) && $f['after'] !== null) {
            $q->where('id', '>', (int) $f['after']);
        } elseif (! empty($f['since'])) {
            $q->where('created_at', '>=', TicketView::time($f['since']));
        }
        match ($f['author'] ?? 'tilen') {
            // People in the UI (any human actor).
            'tilen' => $q->where(fn ($w) => $w->where('author_id', Actor::TILEN)->orWhere('author_id', 'like', HumanActors::USER_PREFIX.'%')),
            'agents' => $q->where('author_type', 'agent'),
            default => null,
        };
        if (! empty($f['inputs_only'])) {
            $q->where('is_tilen_input', true);
        }
        if (! empty($f['project_id'])) {
            $q->whereHas('ticket', fn ($t) => $t->where('project_id', $f['project_id']));
        }

        $rows = $q->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $last = $rows->last();

        return [
            'data' => $rows->map(fn (TicketComment $c) => $this->item($c))->values()->all(),
            // Store it and pass it back as ?after=; it stays put when the page is empty.
            'next_cursor' => $last ? (string) $last->id : (isset($f['after']) && $f['after'] !== null ? (string) $f['after'] : null),
            'has_more' => $hasMore,
        ];
    }

    private function item(TicketComment $c): array
    {
        $t = $c->ticket;
        $p = $t->project;

        return [
            'id' => $c->id,
            'cursor' => (string) $c->id,
            'body' => $c->body,
            'is_tilen_input' => $c->is_tilen_input,
            'input_choice' => $c->input_choice,
            'author_type' => $c->author_type,
            'author_id' => $c->author_id,
            'author_name' => $this->view->name($c->author_id),
            'created_at' => $c->created_at?->toIso8601String(),
            'ticket' => [
                'id' => $t->id,
                'key' => $t->key,
                'title' => $t->title,
                'status' => $t->status,
                'status_label' => BoardColumns::label($t->status),
                'assignee_type' => $t->assignee_type,
                'assignee_id' => $t->assignee_id,
                'assignee_name' => $this->view->name($t->assignee_id),
                'ask' => $t->ask,
                'needs_pm_pickup' => $t->needsPmPickup(),
                'url' => TicketView::url($p, $t->key),
            ],
            'project' => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'pm_agent_id' => $p->owner_agent_id,
                'pm_name' => $this->view->name($p->owner_agent_id),
            ],
        ];
    }
}
