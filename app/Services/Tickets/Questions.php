<?php

namespace App\Services\Tickets;

use App\Models\Agent;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use App\Support\BoardColumns;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * r4 "Questions for you" panel (design/r4/SPEC.md "Assumed data"). No table of its own: a question is a ticket's
 * current ask round, derived from the ticket, its events and its owner input comments. One question per ticket
 * (its latest round), id 'q-' + ticket id.
 *
 * Round: starts at the ticket's latest asking event (created in waiting_tilen, moved into it, or ask_set while in it;
 * old rows without one: waiting_since) and ends when the ticket leaves waiting_tilen or is archived.
 * Answer: the latest live (not retracted) owner input comment posted in the round.
 * State:
 * - closed    = the PM picked the answer up (input_acked at/after it: ack-input or the move out), or the round ended;
 * - answered  = an answer the PM has not picked up yet (needs_pm_pickup), the ticket still waiting;
 * - dismissed = no answer, the owner hid it (latest ask_dismissed event of the round not followed by ask_restored);
 * - open      = otherwise (live ticket in waiting_tilen with an ask and no answer since the ask).
 * A new asking event starts a new round (open again, a dismissal is forgotten). Dismiss / restore only write
 * ticket events (ask_dismissed / ask_restored): the ticket, needs_pm_pickup and the comments are untouched, and the
 * agent view (agentView) still reports a dismissed question as open.
 */
final class Questions
{
    /** Attribute / payload key of the open count (state=open, dismissed not counted). */
    public const COUNT = 'open_questions_count';

    public const STATES = ['open', 'answered', 'closed', 'dismissed'];

    public const DISMISSED = 'ask_dismissed';

    public const RESTORED = 'ask_restored';

    /** The panel's closed group (closed + dismissed, newest first) is capped. */
    public const CLOSED_LIMIT = 20;

    private const ID_PREFIX = 'q-';

    private const EVENT_TYPES = ['created', 'moved', 'ask_set', 'input_acked', 'archived', 'unarchived', self::DISMISSED, self::RESTORED];

    private const TICKET_COLUMNS = ['id', 'project_id', 'key', 'status', 'ask', 'ask_context', 'ask_options', 'waiting_since',
        'tilen_input_at', 'tilen_input_ack_at', 'created_by', 'created_by_type', 'archived_at', 'created_at', 'updated_at'];

    public static function id(Ticket $ticket): string
    {
        return self::ID_PREFIX.$ticket->id;
    }

    /** 'q-tk-…' -> 'tk-…'; null for anything else (e.g. a legacy questions.id 'q-<ulid>', which never starts with t). */
    public static function ticketIdFrom(string $questionId): ?string
    {
        return str_starts_with($questionId, self::ID_PREFIX.'tk-') ? substr($questionId, strlen(self::ID_PREFIX)) : null;
    }

    // ---------------------------------------------------------------- counts

    /** Sets COUNT on each project without it: three queries for any number of projects (live waiting tickets only). */
    public static function loadOpenCounts(iterable $projects): void
    {
        $projects = collect($projects)->filter(fn (Project $p) => $p->getAttribute(self::COUNT) === null)->keyBy('id');
        if ($projects->isEmpty()) {
            return;
        }
        $counts = array_fill_keys($projects->keys()->all(), 0);
        $tickets = OpenAsks::scope(Ticket::query()->whereIn('project_id', $projects->keys()))->get(self::TICKET_COLUMNS);
        foreach (self::rounds($tickets) as $round) {
            if ($round['state'] === 'open') {
                $counts[$round['ticket']->project_id]++;
            }
        }
        foreach ($projects as $id => $p) {
            $p->setAttribute(self::COUNT, $counts[$id]);
            $p->syncOriginalAttribute(self::COUNT); // never written back on a later save()
        }
    }

    /** The open count from loadOpenCounts(), else computed for this project. */
    public static function count(Project $project): int
    {
        self::loadOpenCounts([$project]);

        return (int) $project->getAttribute(self::COUNT);
    }

    // ---------------------------------------------------------------- lists

    /**
     * The panel: {open (oldest asked first), answered (newest answer first), closed (closed + dismissed, newest first,
     * capped at CLOSED_LIMIT), has_any (the project ever had a question)}. $state open|answered|closed|dismissed keeps
     * only that state (dismissed and closed land in the closed group); all keeps everything.
     *
     * @return array{open: list<array>, answered: list<array>, closed: list<array>, has_any: bool}
     */
    public static function panel(Project $project, ?TicketView $view = null, string $state = 'all', bool $forAgents = false): array
    {
        $all = self::all($project, $view, $forAgents);
        $items = $state === 'all' ? $all : $all->where('state', $state);
        $ts = fn (?string $iso) => $iso === null ? 0 : strtotime($iso);

        return [
            'open' => $items->where('state', 'open')->sortBy([fn ($a, $b) => [$ts($a['asked_at']), $a['ticket_key']] <=> [$ts($b['asked_at']), $b['ticket_key']]])->values()->all(),
            'answered' => $items->where('state', 'answered')->sortByDesc(fn ($q) => $ts($q['answered_at']))->values()->all(),
            'closed' => $items->whereIn('state', ['closed', 'dismissed'])->sortByDesc(fn ($q) => $ts($q['closed_at'] ?? $q['dismissed_at']))
                ->take(self::CLOSED_LIMIT)->values()->all(),
            'has_any' => $all->isNotEmpty(),
        ];
    }

    /** Every question of the project (one per ticket that ever waited on the owner with an ask), unsorted. */
    public static function all(Project $project, ?TicketView $view = null, bool $forAgents = false): Collection
    {
        // Every ticket that ever waited with an ask still has one, or has an ask_set event (an ask cleared later).
        $tickets = Ticket::query()->where('project_id', $project->id)
            ->where(fn ($q) => $q->where(fn ($a) => $a->whereNotNull('ask')->where('ask', '!=', ''))
                ->orWhereIn('id', TicketEvent::query()->select('ticket_id')->where('project_id', $project->id)->where('type', 'ask_set')))
            ->get(self::TICKET_COLUMNS);

        return self::present($project, self::rounds($tickets), $view, $forAgents);
    }

    /** One question by id in this project; null when unknown, another project's, or the ticket never asked. */
    public static function find(Project $project, string $questionId): ?array
    {
        $ticketId = self::ticketIdFrom($questionId);
        $ticket = $ticketId === null ? null : Ticket::query()->where('project_id', $project->id)->whereKey($ticketId)->first();

        return $ticket === null ? null : (self::rounds(new EloquentCollection([$ticket]))[$ticket->id] ?? null);
    }

    /** The JSON shape of one round (find()). */
    public static function view(Project $project, array $round, ?TicketView $view = null): array
    {
        return self::present($project, [$round['ticket']->id => $round], $view)->first();
    }

    /** Agents see a dismissed question as still open (the owner only hid it on their side). */
    public static function agentView(array $q): array
    {
        return $q['state'] === 'dismissed' ? [...$q, 'state' => 'open', 'dismissed_at' => null] : $q;
    }

    // ---------------------------------------------------------------- derivation

    /**
     * Two queries for any set of tickets: their round events and their live owner input comments.
     *
     * @return array<string, array> ticket id => round (tickets that never waited with an ask are left out)
     */
    private static function rounds(Collection $tickets): array
    {
        if ($tickets->isEmpty()) {
            return [];
        }
        $ids = $tickets->modelKeys();
        $events = TicketEvent::query()->whereIn('ticket_id', $ids)->whereIn('type', self::EVENT_TYPES)->orderBy('id')
            ->get(['id', 'ticket_id', 'type', 'from', 'to', 'actor_id', 'actor_type', 'source', 'created_at'])->groupBy('ticket_id');
        $inputs = TicketComment::query()->whereIn('ticket_id', $ids)->where('is_tilen_input', true)->whereNull('retracted_at')->orderBy('id')
            ->get(['id', 'ticket_id', 'author_id', 'body', 'input_choice', 'created_at'])->groupBy('ticket_id');

        $out = [];
        foreach ($tickets as $t) {
            if (($round = self::round($t, $events[$t->id] ?? collect(), $inputs[$t->id] ?? collect())) !== null) {
                $out[$t->id] = $round;
            }
        }

        return $out;
    }

    private static function round(Ticket $t, Collection $events, Collection $inputs): ?array
    {
        $w = BoardColumns::WAITING_TILEN;
        $inW = false;
        $asked = $end = $dismissed = null;
        $acks = [];
        $lastAsk = null;
        foreach ($events as $e) {
            $to = $e->to ?? [];
            $from = $e->from ?? [];
            $start = false;
            switch ($e->type) {
                case 'created':
                    $start = $inW = ($to['status'] ?? null) === $w;
                    break;
                case 'moved':
                    $toW = ($to['status'] ?? null) === $w;
                    $fromW = ($from['status'] ?? null) === $w;
                    if ($toW && ! $fromW) {
                        $start = $inW = true;
                    } elseif ($fromW && ! $toW) {
                        $inW = false;
                        $end = $e;
                    }
                    break;
                case 'ask_set':
                    $lastAsk = $from['ask'] ?? $lastAsk;
                    $lastAsk = $to['ask'] ?? $lastAsk;
                    $start = $inW;
                    break;
                case 'archived':
                    $end = $inW ? $e : $end;
                    break;
                case 'unarchived':
                    $end = $inW ? null : $end;
                    break;
                case 'input_acked':
                    $acks[] = $e;
                    break;
                case self::DISMISSED:
                    $dismissed = $e;
                    break;
                case self::RESTORED:
                    $dismissed = null;
                    break;
            }
            if ($start) {
                [$asked, $end, $dismissed] = [$e, null, null];
            }
        }

        $live = $t->status === $w && $t->archived_at === null && filled($t->ask);
        if ($asked === null && ! $live) {
            return null; // never waited on the owner with an ask
        }
        $askedAt = $asked?->created_at ?? $t->waiting_since ?? $t->created_at;
        $endAt = $live ? null : ($end?->created_at ?? $t->archived_at ?? $t->updated_at);

        $answer = $inputs->filter(fn (TicketComment $c) => $c->created_at->gte($askedAt) && ($endAt === null || $c->created_at->lte($endAt)))->last();
        $pickedUp = null;
        if ($answer !== null) {
            $ack = collect($acks)->first(fn (TicketEvent $a) => $a->created_at->gte($answer->created_at));
            $pickedUp = $ack?->created_at
                ?? ($t->tilen_input_ack_at !== null && $t->tilen_input_ack_at->gte($answer->created_at) ? $t->tilen_input_ack_at : null);
        }

        [$state, $closedAt] = match (true) {
            $pickedUp !== null => ['closed', $pickedUp],
            ! $live => ['closed', $endAt],
            $answer !== null => ['answered', null],
            $dismissed !== null => ['dismissed', null],
            default => ['open', null],
        };

        return [
            'ticket' => $t,
            'text' => $t->ask ?? $lastAsk,
            'asked' => $asked,
            'asked_at' => $askedAt,
            'state' => $state,
            'answer' => $answer,
            'picked_up_at' => $pickedUp,
            'closed_at' => $closedAt,
            'dismissed_at' => $state === 'dismissed' ? $dismissed->created_at : null,
        ];
    }

    /** @param  array<string, array>  $rounds */
    private static function present(Project $project, array $rounds, ?TicketView $view, bool $forAgents = false): Collection
    {
        $view ??= new TicketView;
        $roles = $rounds === [] ? [] : Agent::query()->pluck('role_label', 'id')->all();
        $iso = fn (?CarbonInterface $at) => $at?->toIso8601String();

        return collect($rounds)->map(function (array $r) use ($project, $view, $roles, $iso, $forAgents) {
            $t = $r['ticket'];
            $asked = $r['asked'];
            $by = $asked?->actor_id ?? $t->created_by;
            $type = $asked?->actor_type ?? $t->created_by_type;
            $answer = $r['answer'];
            $choice = $answer?->input_choice;
            $q = [
                'id' => self::id($t),
                'ticket_id' => $t->id,
                'ticket_key' => $t->key,
                'project_id' => $t->project_id,
                'text' => (string) $r['text'],
                'context' => $t->ask_context,
                // [] = free text; ["Yes","No"] = yes/no; otherwise multiple choice (plus the optional note).
                'options' => $t->ask_options ?? [],
                // The answer box always takes text: the answer itself (free text) or a note next to a choice.
                'allow_text' => true,
                'asked_by' => [
                    'id' => $by,
                    'name' => $view->name($by),
                    'role' => self::role($by, $type, $project, $roles),
                    'type' => $type,
                    // api | mcp | ui (viaLabel); null when the creator is the fallback (no asking event).
                    'source' => $asked?->source,
                ],
                'asked_at' => $iso($r['asked_at']),
                'state' => $r['state'],
                'answer_choice' => $choice,
                // The drawer stores a choice-only input with the choice as its body: that is no text answer.
                'answer_text' => $answer === null || ($choice !== null && $answer->body === $choice) ? null : $answer->body,
                'answered_at' => $iso($answer?->created_at),
                'picked_up_at' => $iso($r['picked_up_at']),
                'closed_at' => $iso($r['closed_at']),
                'dismissed_at' => $iso($r['dismissed_at']),
            ];

            return $forAgents ? self::agentView($q) : $q;
        })->values();
    }

    /** "PM" for the project's PM agent, an agent's role label, Owner / Member for people. */
    private static function role(?string $id, ?string $type, Project $project, array $agentRoles): string
    {
        if ($id !== null && $id === $project->owner_agent_id) {
            return 'PM';
        }
        if ($id !== null && array_key_exists($id, $agentRoles)) {
            return filled($agentRoles[$id]) ? (string) $agentRoles[$id] : 'Agent';
        }
        if (HumanActors::isHumanId($id)) {
            return $id === HumanActors::ownerId() ? 'Owner' : 'Member';
        }

        return $type === 'agent' ? 'Agent' : '';
    }
}
