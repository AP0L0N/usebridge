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

    /** Text that may mention tickets: stored with [[ticket:…]] tokens (StoresTicketRefs, TicketRefs). */
    public const TICKET_REF_FIELDS = ['note'];

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
