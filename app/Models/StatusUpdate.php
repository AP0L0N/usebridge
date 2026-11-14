<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\StoresTicketRefs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatusUpdate extends Model
{
    use BelongsToWorkspace, StoresTicketRefs;

    /**
     * Text that may mention tickets: stored with [[ticket:…]] tokens (StoresTicketRefs, TicketRefs). Column => its max
     * length in characters (varchar), null = TEXT; a value whose tokenized form would not fit is stored as typed.
     *
     * @var array<string, int|null>
     */
    public const TICKET_REF_FIELDS = ['body' => null];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'project_id', 'agent_id', 'body', 'source', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function requiredInputs(): HasMany
    {
        return $this->hasMany(RequiredInput::class);
    }
}
