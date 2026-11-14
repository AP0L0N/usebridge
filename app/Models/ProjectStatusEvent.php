<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\StoresTicketRefs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One PM / person (UI) status write (also note-only updates). Append-only. */
class ProjectStatusEvent extends Model
{
    use BelongsToWorkspace, StoresTicketRefs;

    /**
     * Text that may mention tickets: stored with [[ticket:…]] tokens (StoresTicketRefs, TicketRefs). Column => its max
     * length in characters (varchar), null = TEXT; a value whose tokenized form would not fit is stored as typed.
     *
     * @var array<string, int|null>
     */
    public const TICKET_REF_FIELDS = ['note' => 280];

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
