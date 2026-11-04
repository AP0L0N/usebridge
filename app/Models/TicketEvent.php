<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only ticket history (thread events, side History, project activity feed). */
class TicketEvent extends Model
{
    use BelongsToWorkspace;

    public const TYPES = [
        'created', 'moved', 'assigned', 'labelled', 'priority', 'due', 'blocked', 'unblocked', 'ask_set',
        'edited', 'input_acked', 'archived', 'unarchived', 'commented',
        // r4 Questions panel: the owner hid / brought back the current ask (App\Services\Tickets\Questions).
        'ask_dismissed', 'ask_restored',
        // r4 QA MED-1: a newer owner input superseded pending ones (to: {comment_ids, by_comment_id}).
        'input_superseded',
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
