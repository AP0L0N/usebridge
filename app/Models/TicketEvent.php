<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Support\BoardColumns;
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
        // Workstreams: the ticket moved to another workstream (from/to: {workstream_id, name}).
        'workstream',
        // The waiting-on-input flag was set / cleared (to / from: {waiting_on_input_at}).
        'waiting_set', 'waiting_cleared',
    ];

    /** to.reason of the two events migration 2026_11_25_100100 logged per ticket it moved out of waiting_tilen. */
    public const WAITING_COLUMN_REMOVED = 'waiting_column_removed';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['from' => 'array', 'to' => 'array'];
    }

    /**
     * The event put an ask in front of the owner: a new ask, the waiting-on-input flag set, or (older history) a move
     * into the removed waiting_tilen column. Not the flag the column-removal migration carried over.
     */
    public function startsWaiting(): bool
    {
        return match ($this->type) {
            'ask_set' => true,
            'waiting_set' => ($this->to['reason'] ?? null) !== self::WAITING_COLUMN_REMOVED,
            'created', 'moved' => ($this->to['status'] ?? null) === BoardColumns::WAITING_TILEN && ($this->from['status'] ?? null) !== BoardColumns::WAITING_TILEN,
            default => false,
        };
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
