<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\StoresTicketRefs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends Model
{
    use BelongsToWorkspace, StoresTicketRefs;

    /**
     * Text that may mention tickets: stored with [[ticket:…]] tokens (StoresTicketRefs, TicketRefs). Column => its max
     * length in characters (varchar), null = TEXT; a value whose tokenized form would not fit is stored as typed.
     *
     * @var array<string, int|null>
     */
    public const TICKET_REF_FIELDS = ['text' => 1000, 'context' => 500, 'answer_text' => null];

    public const TYPES = ['yesno', 'text'];

    public const STATES = ['open', 'answered', 'closed'];

    public const CHOICES = ['yes', 'no', 'skip', 'other'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = ['state' => 'open'];

    protected $fillable = ['id', 'project_id', 'text', 'context', 'type', 'asked_by', 'state', 'answer_choice', 'answer_text', 'answered_at'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'superseded_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isOpen(): bool
    {
        return $this->state === 'open';
    }

    public function toView(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'text' => $this->text,
            'context' => $this->context,
            'type' => $this->type,
            'asked_by' => $this->asked_by,
            'state' => $this->state,
            'answer_choice' => $this->answer_choice,
            'answer_text' => $this->answer_text,
            'answered_at' => $this->answered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
