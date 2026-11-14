<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\StoresTicketRefs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequiredInput extends Model
{
    use BelongsToWorkspace, StoresTicketRefs;

    /**
     * Text that may mention tickets: stored with [[ticket:…]] tokens (StoresTicketRefs, TicketRefs). Column => its max
     * length in characters (varchar), null = TEXT; a value whose tokenized form would not fit is stored as typed.
     *
     * @var array<string, int|null>
     */
    public const TICKET_REF_FIELDS = ['text' => null];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'project_id', 'status_update_id', 'text', 'waiting_on', 'since', 'resolved_at', 'resolved_by'];

    protected function casts(): array
    {
        return [
            'since' => 'datetime',
            'resolved_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
