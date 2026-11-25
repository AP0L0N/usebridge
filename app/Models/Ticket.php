<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\StoresTicketRefs;
use App\Services\Tickets\HumanActors;
use App\Services\Tickets\Workstreams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A Kanban ticket (SPEC §6). Never hard-deleted: archived_at is the soft archive. */
class Ticket extends Model
{
    use BelongsToWorkspace, StoresTicketRefs;

    /**
     * Text that may mention tickets: stored with [[ticket:…]] tokens (StoresTicketRefs, TicketRefs). Column => its max
     * length in characters (varchar), null = TEXT; a value whose tokenized form would not fit is stored as typed.
     *
     * @var array<string, int|null>
     */
    public const TICKET_REF_FIELDS = ['description' => null, 'ask' => 280, 'ask_context' => 500, 'blocked_reason' => 140];

    public const PRIORITIES = ['p1', 'p2', 'p3'];

    public const ASSIGNEE_TYPES = ['agent', 'human'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $attributes = ['priority' => 'p3', 'position' => 0];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'due_on' => 'date:Y-m-d',
            'ask_options' => 'array',
            'waiting_on_input_at' => 'datetime',
            'tilen_input_at' => 'datetime',
            'tilen_input_ack_at' => 'datetime',
            'done_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // workstream_id is NOT NULL: a row written without one (seeders, carry-over) lands in the project's default.
        static::creating(function (Ticket $ticket) {
            if ($ticket->workstream_id === null && $ticket->project_id !== null) {
                $ticket->workstream_id = Workstreams::defaultIdFor((string) $ticket->project_id);
            }
        });
    }

    /** withTrashed: a soft-deleted project's tickets still render (API lists filter them out). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function workstream(): BelongsTo
    {
        return $this->belongsTo(Workstream::class)->withTrashed();
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'ticket_label')->orderBy('name');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class);
    }

    /** Public share links (/s/{token}); only the workspace owner manages or sees them (ShareLinks). */
    public function shareLinks(): HasMany
    {
        return $this->hasMany(TicketShareLink::class);
    }

    /** The read state of the human viewing (HumanActors::viewerId: the web user, else the workspace owner). */
    public function viewerReadState(): HasOne
    {
        return $this->hasOne(TicketReadState::class)->where('user', HumanActors::viewerId());
    }

    /**
     * Flagged "input is needed from the workspace owner" (waiting_on_input_at = when), in any column. Set and cleared
     * explicitly (TicketService::update); an owner input comment never clears it.
     */
    public function isWaitingOnOwner(): bool
    {
        return $this->waiting_on_input_at !== null;
    }

    public function scopeWaitingOnInput(Builder $q): Builder
    {
        return $q->whereNotNull('waiting_on_input_at');
    }

    /**
     * Flagged, and no human has posted a live (not retracted, not superseded) owner input comment since the flag was
     * set (at or after waiting_on_input_at, like a question's answer in Questions): the highlight on the card and in
     * the ticket. Uses the comments relation when it is loaded.
     */
    public function waitingOnInputOutstanding(): bool
    {
        $since = $this->waiting_on_input_at;
        if ($since === null) {
            return false;
        }
        $comments = $this->relationLoaded('comments')
            ? $this->comments
            : $this->comments()->where('is_tilen_input', true)->get(['id', 'ticket_id', 'is_tilen_input', 'retracted_at', 'superseded_at', 'created_at']);

        return ! $comments->contains(fn (TicketComment $c) => $c->is_tilen_input && $c->retracted_at === null
            && $c->superseded_at === null && $c->created_at !== null && $c->created_at->gte($since));
    }

    /** A human sent input (tilen_input_at) the PM hasn't acknowledged yet (ack missing or older than the latest input). */
    public function needsPmPickup(): bool
    {
        return $this->tilen_input_at !== null
            && ($this->tilen_input_ack_at === null || $this->tilen_input_ack_at->lt($this->tilen_input_at));
    }

    public function scopeNeedsPmPickup(Builder $q): Builder
    {
        return $q->whereNotNull('tilen_input_at')
            ->where(fn (Builder $w) => $w->whereNull('tilen_input_ack_at')->orWhereColumn('tilen_input_ack_at', '<', 'tilen_input_at'));
    }

    /**
     * Query twin of TicketView::unread(): an agent comment newer than the human's last_seen_at, on a ticket that
     * involves them (flagged waiting on the owner's input, or they have commented). $human = actor id, default the viewer
     * (HumanActors::viewerId). Drives GET /api/tickets?unread_for_me=1 (alias unread_for_owner, unread_for_tilen).
     */
    public function scopeUnreadFor(Builder $q, ?string $human = null): Builder
    {
        $human ??= HumanActors::viewerId();
        $seen = fn ($r) => $r->selectRaw('1')->from('ticket_read_states')
            ->whereColumn('ticket_read_states.ticket_id', 'ticket_comments.ticket_id')->where('ticket_read_states.user', $human);

        return $q
            ->where(fn (Builder $w) => $w->whereNotNull('waiting_on_input_at')
                ->orWhereHas('comments', fn (Builder $c) => $c->whereNull('retracted_at')->where('author_id', $human)))
            ->whereHas('comments', fn (Builder $c) => $c->whereNull('retracted_at')->where('author_type', 'agent')
                ->where(fn ($w) => $w->whereNotExists($seen)
                    ->orWhereExists(fn ($r) => $seen($r)->whereColumn('ticket_comments.created_at', '>', 'ticket_read_states.last_seen_at'))));
    }

    /** Ticket by key (CA-12, case-insensitive) or id (tk-…). */
    public static function findByRef(string $ref): ?self
    {
        return str_starts_with($ref, 'tk-')
            ? static::query()->find($ref)
            : static::query()->where('key', strtoupper($ref))->first();
    }
}
