<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkItem extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'project_id', 'title', 'owner_agent_id', 'due_at', 'status', 'done_looks_like',
    ];

    protected function casts(): array
    {
        return ['due_at' => 'date:Y-m-d'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'owner_agent_id');
    }
}
