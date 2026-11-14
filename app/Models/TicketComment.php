<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\StoresTicketRefs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ticket comment. The auto-increment id is the answers-relay feed cursor. Soft retract only (retracted_at).
 * superseded_at / superseded_by_comment_id: an owner input replaced by a newer one before the PM picked it up. */
class TicketComment extends Model
{
    use BelongsToWorkspace, StoresTicketRefs;

    /**
     * Text that may mention tickets: stored with [[ticket:…]] tokens (StoresTicketRefs, TicketRefs). Column => its max
     * length in characters (varchar), null = TEXT; a value whose tokenized form would not fit is stored as typed.
     *
     * @var array<string, int|null>
     */
    public const TICKET_REF_FIELDS = ['body' => null];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_tilen_input' => 'boolean',
            'edited_at' => 'datetime',
            'retracted_at' => 'datetime',
            'superseded_at' => 'datetime',
            'superseded_by_comment_id' => 'integer',
        ];
    }

    /** A live owner input replaced by a newer one before pickup (TicketService::comment, "Change answer"). */
    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
