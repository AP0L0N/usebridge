<?php

namespace App\Models;

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

    public function tilenReadState(): HasOne
    {
        return $this->hasOne(TicketReadState::class)->where('user', 'tilen');
    }

    public function isWaitingOnTilen(): bool
    {
        return $this->status === BoardColumns::WAITING_TILEN;
    }

    /** Tilen sent input the PM hasn't acknowledged yet (ack missing or older than the latest input). */
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

    /** Ticket by key (CA-12, case-insensitive) or id (tk-…). */
    public static function findByRef(string $ref): ?self
    {
        return str_starts_with($ref, 'tk-')
            ? static::query()->find($ref)
            : static::query()->where('key', strtoupper($ref))->first();
    }
}
