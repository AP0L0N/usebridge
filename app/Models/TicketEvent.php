<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only ticket history (thread events, side History, project activity feed). */
class TicketEvent extends Model
{
    public const TYPES = [
        'created', 'moved', 'assigned', 'labelled', 'priority', 'due', 'blocked', 'unblocked', 'ask_set',
        'edited', 'input_acked', 'archived', 'unarchived', 'commented',
    ];

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['from' => 'array', 'to' => 'array'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
