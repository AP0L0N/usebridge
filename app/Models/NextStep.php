<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NextStep extends Model
{
    use BelongsToWorkspace;

    public const KINDS = ['in_progress', 'blocker'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'project_id', 'kind', 'text', 'owner', 'agent_id', 'position', 'done_at', 'done_by'];

    protected function casts(): array
    {
        return ['done_at' => 'datetime', 'position' => 'integer', 'superseded_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function toView(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'kind' => $this->kind,
            'text' => $this->text,
            'owner' => $this->owner,
            'agent_id' => $this->agent_id,
            'position' => $this->position,
            'done' => $this->done_at !== null,
            'done_at' => $this->done_at?->toIso8601String(),
            'done_by' => $this->done_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
