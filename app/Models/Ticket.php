<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Services\Tickets\HumanActors;
use App\Support\BoardColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A Kanban ticket (SPEC §6). Never hard-deleted: archived_at is the soft archive. */
class Ticket extends Model
{
    use BelongsToWorkspace;

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
            'waiting_since' => 'datetime',
            'tilen_input_at' => 'datetime',
            'tilen_input_ack_at' => 'datetime',
            'done_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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

    /** The read state of the human viewing (HumanActors::viewerId: the web user, else the workspace owner). */
    public function viewerReadState(): HasOne
    {
        return $this->hasOne(TicketReadState::class)->where('user', HumanActors::viewerId());
    }

    /** In the waiting_tilen column ("Waiting on <owner>"). */
    public function isWaitingOnOwner(): bool
    {
        return $this->status === BoardColumns::WAITING_TILEN;
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
     * involves them (waiting on the owner, or they have commented). $human = actor id, default the viewer
     * (HumanActors::viewerId). Drives GET /api/tickets?unread_for_me=1 (alias unread_for_owner, unread_for_tilen).
     */
    public function scopeUnreadFor(Builder $q, ?string $human = null): Builder
    {
        $human ??= HumanActors::viewerId();
        $seen = fn ($r) => $r->selectRaw('1')->from('ticket_read_states')
            ->whereColumn('ticket_read_states.ticket_id', 'ticket_comments.ticket_id')->where('ticket_read_states.user', $human);

        return $q
            ->where(fn (Builder $w) => $w->where('status', BoardColumns::WAITING_TILEN)
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
