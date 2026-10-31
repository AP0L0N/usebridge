<?php

namespace App\Services\Tickets;

use App\Models\Agent;
use App\Models\Label;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use App\Support\BoardColumns;
use App\Support\TicketPrefix;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Every ticket write (API agents, Tilen in the UI, the carry-over command) goes through here, so the rules live once:
 * - key = {prefix}-{seq} from projects.ticket_seq, bumped under a row lock, never reused.
 * - waiting_tilen needs an ask; entering it sets waiting_since, leaving clears it and auto-acks Tilen's input.
 * - done_at is set on entering done and cleared on leaving. Positions are dense (0..n-1) per column.
 * - every change appends ticket_events and bumps projects.last_activity_at. No hard deletes.
 */
final class TicketService
{
    /** 422 on `ask`: names the waiting_tilen column as this project's board shows it (project label, else workspace default). */
    public static function askRequired(?Project $project): string
    {
        $column = BoardColumns::labelFor(BoardColumns::WAITING_TILEN, $project?->column_labels, $project?->workspace);

        return "Moving into {$column} needs an ask: what do you need answered?";
    }

    /** Validation rules for the editable ticket fields (create: title required). */
    public static function rules(bool $create): array
    {
        $s = $create ? [] : ['sometimes'];

        return [
            'title' => $create ? ['required', 'string', 'max:140'] : ['sometimes', 'required', 'string', 'max:140'],
            'description' => [...$s, 'nullable', 'string', 'max:20000'],
            'status' => [...$s, 'nullable', Rule::in(BoardColumns::keys())],
            'assignee' => ['sometimes', 'nullable', 'string', 'max:120'],
            'assignee_type' => ['sometimes', 'nullable', Rule::in(Ticket::ASSIGNEE_TYPES)],
            'assignee_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'priority' => [...$s, 'nullable', Rule::in(Ticket::PRIORITIES)],
            'due_on' => [...$s, 'nullable', 'date_format:Y-m-d'],
            'blocked_reason' => [...$s, 'nullable', 'string', 'max:140'],
            ...self::askRules(),
            'labels' => ['sometimes', 'nullable', 'array', 'max:20'],
            'labels.*' => ['required', 'string', 'max:24'],
            ...self::placementRules(),
        ];
    }

    /** POST /api/tickets/{ticket}/move and MCP move_ticket. */
    public static function moveRules(): array
    {
        return [
            'status' => ['required', Rule::in(BoardColumns::keys())],
            ...self::placementRules(),
            ...self::askRules(),
        ];
    }

    /** Agent comments (API and MCP). */
    public static function commentRules(): array
    {
        return ['body' => ['required', 'string', 'max:10000']];
    }

    public static function askRules(): array
    {
        return [
            'ask' => ['sometimes', 'nullable', 'string', 'max:280'],
            'ask_context' => ['sometimes', 'nullable', 'string', 'max:500'],
            'ask_options' => ['sometimes', 'nullable', 'array', 'max:4'],
            'ask_options.*' => ['required', 'string', 'max:60', 'distinct'],
        ];
    }

    public static function placementRules(): array
    {
        return [
            'position' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'before_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'after_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    // ---------------------------------------------------------------- create

    /**
     * @param  array  $data  validated fields (see rules())
     * @param  array  $extra  raw attributes for the carry-over (created_at, waiting_since, source_type/id, created_by…)
     */
    public function create(Project $project, array $data, Actor $actor, array $extra = [], bool $allowArchivedProject = false): Ticket
    {
        abort_if(! $allowArchivedProject && $project->isArchived(), 409, "Project {$project->name} is archived; reopen it before adding tickets.");

        return DB::transaction(function () use ($project, $data, $actor, $extra) {
            $status = $data['status'] ?? BoardColumns::TODO;
            $ask = $this->askFields($data);
            if ($status === BoardColumns::WAITING_TILEN && blank($ask['ask'] ?? null)) {
                throw ValidationException::withMessages(['ask' => self::askRequired($project)]);
            }
            [$assigneeType, $assigneeId] = $this->assignee($data) ?? [null, null];
            $now = Carbon::now();

            $ticket = new Ticket;
            $ticket->forceFill([
                'id' => 'tk-'.Str::lower((string) Str::ulid()),
                'project_id' => $project->id,
                'key' => $this->nextKey($project),
                'title' => trim($data['title']),
                'description' => $data['description'] ?? null,
                'status' => $status,
                'position' => 0,
                'assignee_type' => $assigneeType,
                'assignee_id' => $assigneeId,
                'priority' => $data['priority'] ?? 'p3',
                'due_on' => $data['due_on'] ?? null,
                'blocked_reason' => self::clean($data['blocked_reason'] ?? null),
                ...$ask,
                'waiting_since' => $status === BoardColumns::WAITING_TILEN ? $now : null,
                'done_at' => $status === BoardColumns::DONE ? $now : null,
                'created_by' => $actor->id,
                'created_by_type' => $actor->type,
                ...$extra,
            ])->save();

            $this->place($ticket, $this->placement($data, $ticket));
            if (! empty($data['labels'])) {
                $ticket->labels()->sync($this->labelIds($data['labels']));
            }
            $this->event($ticket, 'created', null, ['status' => $ticket->status, 'title' => $ticket->title, 'key' => $ticket->key], $actor);
            Project::touchActivity($project->id);

            return $ticket->refresh();
        });
    }

    /** {prefix}-{seq}: seq bumped under a row lock so concurrent creates never collide; keys are never reused. */
    private function nextKey(Project $project): string
    {
        $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
        $prefix = $locked->ticket_prefix;
        if (blank($prefix)) {
            $prefix = TicketPrefix::forProject((string) $locked->id, (string) $locked->name, Project::acrossWorkspaces()->whereNotNull('ticket_prefix')->pluck('ticket_prefix'));
        }
        $seq = (int) $locked->ticket_seq;
        do {
            $seq++;
            $key = $prefix.'-'.$seq;
        } while (Ticket::acrossWorkspaces()->where('key', $key)->exists()); // keys are globally unique (for now)
        Project::query()->whereKey($project->id)->toBase()->update(['ticket_seq' => $seq, 'ticket_prefix' => $prefix]);
        $project->ticket_seq = $seq;
        $project->ticket_prefix = $prefix;

        return $key;
    }

    // ---------------------------------------------------------------- update / move

    /** PATCH: any editable field; labels replace the set; status/position/before_id/after_id behave like move. */
    public function update(Ticket $ticket, array $data, Actor $actor): Ticket
    {
        $this->ensureWritable($ticket);

        return DB::transaction(function () use ($ticket, $data, $actor) {
            foreach (['title', 'description'] as $f) {
                if (array_key_exists($f, $data) && $data[$f] !== $ticket->{$f}) {
                    $old = $ticket->{$f};
                    $ticket->{$f} = $f === 'title' ? trim((string) $data[$f]) : $data[$f];
                    $this->event($ticket, 'edited', [$f => $old], [$f => $ticket->{$f}], $actor);
                }
            }
            if (($assignee = $this->assignee($data)) !== null && $assignee !== [$ticket->assignee_type, $ticket->assignee_id]) {
                $from = ['type' => $ticket->assignee_type, 'id' => $ticket->assignee_id];
                [$ticket->assignee_type, $ticket->assignee_id] = $assignee;
                $this->event($ticket, 'assigned', $from, ['type' => $ticket->assignee_type, 'id' => $ticket->assignee_id], $actor);
            }
            if (array_key_exists('priority', $data) && ($data['priority'] ?? 'p3') !== $ticket->priority) {
                $this->event($ticket, 'priority', ['priority' => $ticket->priority], ['priority' => $data['priority'] ?? 'p3'], $actor);
                $ticket->priority = $data['priority'] ?? 'p3';
            }
            if (array_key_exists('due_on', $data) && $data['due_on'] !== $ticket->due_on?->format('Y-m-d')) {
                $this->event($ticket, 'due', ['due_on' => $ticket->due_on?->format('Y-m-d')], ['due_on' => $data['due_on']], $actor);
                $ticket->due_on = $data['due_on'];
            }
            if (array_key_exists('blocked_reason', $data)) {
                $new = self::clean($data['blocked_reason']);
                if ($new !== $ticket->blocked_reason) {
                    $this->event($ticket, $new === null ? 'unblocked' : 'blocked', ['blocked_reason' => $ticket->blocked_reason], ['blocked_reason' => $new], $actor);
                    $ticket->blocked_reason = $new;
                }
            }
            $this->applyAsk($ticket, $data, $actor);
            if (array_key_exists('labels', $data)) {
                $this->syncLabels($ticket, $data['labels'] ?? [], $actor);
            }

            $placementGiven = array_intersect_key($data, array_flip(['position', 'before_id', 'after_id'])) !== [];
            $status = $data['status'] ?? $ticket->status;
            if ($status !== $ticket->status || $placementGiven) {
                $this->moveWithin($ticket, $status, $data, $actor);
            } else {
                $this->checkAsk($ticket);
                $ticket->save();
            }
            Project::touchActivity($ticket->project_id);

            return $ticket->refresh();
        });
    }

    /** POST move: {status, before_id|after_id|position (default end of column), ask… when entering waiting_tilen}. */
    public function move(Ticket $ticket, array $data, Actor $actor): Ticket
    {
        $this->ensureWritable($ticket);

        return DB::transaction(function () use ($ticket, $data, $actor) {
            $this->applyAsk($ticket, $data, $actor);
            $this->moveWithin($ticket, $data['status'], $data, $actor);
            Project::touchActivity($ticket->project_id);

            return $ticket->refresh();
        });
    }

    private function moveWithin(Ticket $ticket, string $status, array $data, Actor $actor): void
    {
        $from = ['status' => $ticket->status, 'position' => $ticket->position];
        $fromStatus = $ticket->status;
        $now = Carbon::now();

        $ticket->status = $status;
        $this->checkAsk($ticket);
        if ($status !== $fromStatus) {
            if ($status === BoardColumns::WAITING_TILEN) {
                $ticket->waiting_since = $now;
            }
            if ($fromStatus === BoardColumns::WAITING_TILEN) {
                $ticket->waiting_since = null;
                // Leaving Waiting on Tilen means the PM picked up Tilen's input.
                if ($ticket->needsPmPickup()) {
                    $ticket->tilen_input_ack_at = $now;
                    $this->event($ticket, 'input_acked', null, ['tilen_input_at' => $ticket->tilen_input_at->toIso8601String(), 'via' => 'move'], $actor);
                }
            }
            $ticket->done_at = $status === BoardColumns::DONE ? $now : null;
        }
        $ticket->save();

        $this->place($ticket, $this->placement($data, $ticket));
        if ($status !== $fromStatus) {
            $this->renumber($ticket->project_id, $fromStatus);
        }
        $to = ['status' => $ticket->status, 'position' => $ticket->position];
        if ($to !== $from) {
            $this->event($ticket, 'moved', $from, $to, $actor);
        }
    }

    /** Insert $ticket at $index (null = end) of its column and renumber the column densely. */
    private function place(Ticket $ticket, ?int $index): void
    {
        $ids = $this->columnIds($ticket->project_id, $ticket->status, $ticket->id);
        if ($ticket->archived_at === null) {
            $index = $index === null ? count($ids) : max(0, min($index, count($ids)));
            array_splice($ids, $index, 0, [$ticket->id]);
        }
        if ($ticket->status === BoardColumns::WAITING_TILEN) {
            // Waiting on Tilen is always oldest waiting_since first; manual positions are ignored.
            $ids = $this->columnIds($ticket->project_id, $ticket->status);
        }
        $this->writePositions($ids);
        $ticket->position = (int) Ticket::query()->whereKey($ticket->id)->value('position');
    }

    private function renumber(string $projectId, string $status): void
    {
        $this->writePositions($this->columnIds($projectId, $status));
    }

    /** @return list<string> live (not archived) ticket ids of a column in board order */
    private function columnIds(string $projectId, string $status, ?string $except = null): array
    {
        $q = Ticket::query()->where('project_id', $projectId)->where('status', $status)->whereNull('archived_at');
        if ($except !== null) {
            $q->whereKeyNot($except);
        }
        $status === BoardColumns::WAITING_TILEN
            ? $q->orderBy('waiting_since')->orderBy('key')
            : $q->orderBy('position')->orderBy('created_at')->orderBy('id');

        return $q->pluck('id')->all();
    }

    /** Renumbering does not touch updated_at (only the moved ticket changed). */
    private function writePositions(array $ids): void
    {
        $current = Ticket::query()->whereIn('id', $ids)->pluck('position', 'id');
        foreach (array_values($ids) as $i => $id) {
            if ((int) ($current[$id] ?? -1) !== $i) {
                Ticket::query()->whereKey($id)->toBase()->update(['position' => $i]);
            }
        }
    }

    /** Target index from before_id / after_id / position; null = end of column. */
    private function placement(array $data, Ticket $ticket): ?int
    {
        foreach (['before_id' => 0, 'after_id' => 1] as $field => $offset) {
            if (! empty($data[$field])) {
                $ref = Ticket::findByRef($data[$field]);
                if (! $ref || $ref->project_id !== $ticket->project_id || $ref->status !== $ticket->status || $ref->archived_at !== null || $ref->id === $ticket->id) {
                    throw ValidationException::withMessages([$field => "$field must be another live ticket in the target column ({$ticket->status})."]);
                }
                $ids = $this->columnIds($ticket->project_id, $ticket->status, $ticket->id);

                return (int) array_search($ref->id, $ids, true) + $offset;
            }
        }

        return isset($data['position']) ? (int) $data['position'] : null;
    }

    private function applyAsk(Ticket $ticket, array $data, Actor $actor): void
    {
        $ask = $this->askFields($data);
        $from = [];
        $to = [];
        foreach ($ask as $f => $v) {
            if ($v !== $ticket->{$f}) {
                $from[$f] = $ticket->{$f};
                $to[$f] = $v;
                $ticket->{$f} = $v;
            }
        }
        if ($to !== []) {
            $this->event($ticket, 'ask_set', $from, $to, $actor);
        }
    }

    /** @return array<string, mixed> only the ask fields present in $data, normalised */
    private function askFields(array $data): array
    {
        $out = [];
        if (array_key_exists('ask', $data)) {
            $out['ask'] = self::clean($data['ask']);
        }
        if (array_key_exists('ask_context', $data)) {
            $out['ask_context'] = self::clean($data['ask_context']);
        }
        if (array_key_exists('ask_options', $data)) {
            $opts = array_values(array_filter(array_map(fn ($o) => trim((string) $o), $data['ask_options'] ?? []), fn ($o) => $o !== ''));
            $out['ask_options'] = $opts === [] ? null : $opts;
        }

        return $out;
    }

    private function checkAsk(Ticket $ticket): void
    {
        if ($ticket->status === BoardColumns::WAITING_TILEN && blank($ticket->ask)) {
            throw ValidationException::withMessages(['ask' => self::askRequired($ticket->project)]);
        }
    }

    // ---------------------------------------------------------------- ack / archive

    public function ackInput(Ticket $ticket, Actor $actor): Ticket
    {
        $this->ensureWritable($ticket);
        if (! $ticket->needsPmPickup()) {
            throw ValidationException::withMessages(['ticket' => "Nothing to acknowledge on {$ticket->key}: no Tilen input waiting for pickup."]);
        }

        return DB::transaction(function () use ($ticket, $actor) {
            $ticket->tilen_input_ack_at = Carbon::now();
            $ticket->save();
            $this->event($ticket, 'input_acked', null, ['tilen_input_at' => $ticket->tilen_input_at->toIso8601String(), 'via' => 'ack'], $actor);
            Project::touchActivity($ticket->project_id);

            return $ticket->refresh();
        });
    }

    public function archive(Ticket $ticket, Actor $actor): Ticket
    {
        if ($ticket->archived_at !== null) {
            return $ticket;
        }

        return DB::transaction(function () use ($ticket, $actor) {
            $ticket->archived_at = Carbon::now();
            $ticket->save();
            $this->renumber($ticket->project_id, $ticket->status);
            $this->event($ticket, 'archived', null, ['status' => $ticket->status], $actor);
            Project::touchActivity($ticket->project_id);

            return $ticket->refresh();
        });
    }

    public function unarchive(Ticket $ticket, Actor $actor): Ticket
    {
        abort_if($ticket->project->isArchived(), 409, "Project {$ticket->project->name} is archived; reopen it first.");
        if ($ticket->archived_at === null) {
            return $ticket;
        }

        return DB::transaction(function () use ($ticket, $actor) {
            $ticket->archived_at = null;
            $ticket->save();
            $this->place($ticket, null);
            $this->event($ticket, 'unarchived', null, ['status' => $ticket->status, 'position' => $ticket->position], $actor);
            Project::touchActivity($ticket->project_id);

            return $ticket->refresh();
        });
    }

    // ---------------------------------------------------------------- comments

    /**
     * Post a comment. A Tilen input comment (is_tilen_input, Tilen only) sets tickets.tilen_input_at, so the
     * ticket needs PM pickup until acked or moved; the status never changes. "Change answer" = another input comment.
     */
    public function comment(Ticket $ticket, Actor $actor, string $body, bool $isInput = false, ?string $choice = null, ?CarbonInterface $at = null, bool $checkWritable = true): TicketComment
    {
        if ($checkWritable) {
            $this->ensureWritable($ticket);
        }
        if (($isInput || $choice !== null) && ! $actor->isTilen()) {
            throw ValidationException::withMessages(['is_tilen_input' => Actor::TILEN_ONLY_UI.'.']);
        }

        return DB::transaction(function () use ($ticket, $actor, $body, $isInput, $choice, $at) {
            $at ??= Carbon::now();
            $comment = new TicketComment;
            $comment->forceFill([
                'ticket_id' => $ticket->id,
                'author_type' => $actor->type === 'agent' ? 'agent' : 'human',
                'author_id' => $actor->id,
                'body' => $body,
                'is_tilen_input' => $isInput,
                'input_choice' => $isInput ? $choice : null,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $comment->timestamps = false;
            $comment->save();

            if ($isInput) {
                $ticket->tilen_input_at = $at;
            }
            $ticket->updated_at = Carbon::now();
            $ticket->save();
            $this->event($ticket, 'commented', null, ['comment_id' => $comment->id, 'is_tilen_input' => $isInput, 'input_choice' => $comment->input_choice], $actor, $at);
            Project::touchActivity($ticket->project_id);

            return $comment;
        });
    }

    /** Soft retract (own comments only). The row stays; the body is hidden in every view. */
    public function retract(TicketComment $comment, Actor $actor): TicketComment
    {
        abort_if($comment->author_id !== $actor->id, 403, 'You can only retract your own comments.');
        if ($comment->retracted_at === null) {
            $comment->retracted_at = Carbon::now();
            $comment->save();
            Project::touchActivity($comment->ticket->project_id);
        }

        return $comment;
    }

    // ---------------------------------------------------------------- labels

    private function syncLabels(Ticket $ticket, array $names, Actor $actor): void
    {
        $old = $ticket->labels()->pluck('name')->sort()->values()->all();
        $ids = $this->labelIds($names);
        $ticket->labels()->sync($ids);
        $new = Label::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')->all();
        if ($old !== $new) {
            $this->event($ticket, 'labelled', ['labels' => $old], ['labels' => $new], $actor);
        }
    }

    /** Names -> label ids; unknown names are created grey (case-insensitive match on existing labels). */
    public function labelIds(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $label = Label::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
                ?? Label::create(['id' => 'lb-'.Str::lower((string) Str::ulid()), 'name' => mb_substr($name, 0, 24), 'color' => 'grey']);
            $ids[$label->id] = $label->id;
        }

        return array_values($ids);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Assignee from `assignee` shorthand (agent id | 'tilen' | free-text human) or assignee_type + assignee_id.
     *
     * @return array{0:?string,1:?string}|null null = not given (leave unchanged)
     */
    private function assignee(array $data): ?array
    {
        if (array_key_exists('assignee', $data)) {
            return $this->assigneeFromText($data['assignee'], 'assignee');
        }
        if (! array_key_exists('assignee_type', $data) && ! array_key_exists('assignee_id', $data)) {
            return null;
        }
        $type = $data['assignee_type'] ?? null;
        $id = self::clean($data['assignee_id'] ?? null);
        if ($type === null) {
            return $this->assigneeFromText($id, 'assignee_id');
        }
        if ($id === null) {
            throw ValidationException::withMessages(['assignee_id' => 'assignee_id is required with assignee_type.']);
        }
        if ($type === 'agent') {
            if (! Agent::query()->whereKey($id)->exists()) {
                throw ValidationException::withMessages(['assignee_id' => "Unknown agent '$id' (GET /api/agents)."]);
            }

            return ['agent', $id];
        }

        return ['human', strcasecmp($id, Actor::TILEN) === 0 ? Actor::TILEN : $id];
    }

    private function assigneeFromText(?string $text, string $field): array
    {
        $text = self::clean($text);
        if ($text === null) {
            return [null, null];
        }
        if (strcasecmp($text, Actor::TILEN) === 0) {
            return ['human', Actor::TILEN];
        }
        if (Agent::query()->whereKey($text)->exists()) {
            return ['agent', $text];
        }

        return ['human', $text];
    }

    /** 409 for edits on an archived ticket or a ticket of an archived project. */
    public function ensureWritable(Ticket $ticket): void
    {
        abort_if($ticket->project->isArchived(), 409, "Project {$ticket->project->name} is archived; tickets are read-only.");
        abort_if($ticket->archived_at !== null, 409, "Ticket {$ticket->key} is archived; unarchive it first.");
    }

    public function event(Ticket $ticket, string $type, ?array $from, ?array $to, Actor $actor, ?CarbonInterface $at = null): TicketEvent
    {
        $e = new TicketEvent;
        $e->forceFill([
            'ticket_id' => $ticket->id,
            'project_id' => $ticket->project_id,
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'actor_id' => $actor->id,
            'actor_type' => $actor->type,
            'source' => $actor->source,
            'created_at' => $at ?? Carbon::now(),
        ]);
        $e->timestamps = false;
        $e->save();

        return $e;
    }

    /**
     * Status/ticket mismatch hints for the PM (SPEC §3): the PM's status stays the source of truth.
     *
     * @return list<array{code:string,count:int,message:string}>
     */
    public static function warnings(Project $project): array
    {
        $status = Project::query()->whereKey($project->id)->value('status');
        if ($status !== 'smooth') {
            return [];
        }
        $n = Ticket::query()->where('project_id', $project->id)->where('status', BoardColumns::WAITING_TILEN)->whereNull('archived_at')->count();

        return $n > 0 ? [[
            'code' => 'status_smooth_but_waiting_tickets',
            'count' => $n,
            'message' => "Project status is Smooth sailing but $n ticket".($n === 1 ? '' : 's').' wait on Tilen.',
        ]] : [];
    }

    public static function clean(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
