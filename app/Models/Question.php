<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends Model
{
    use BelongsToWorkspace;

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
