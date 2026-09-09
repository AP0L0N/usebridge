<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CeoQueueItem extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'reason', 'suggested_action', 'project_id', 'chip_label',
        'status', 'surfaced_at', 'assigned_agent_id',
    ];

    protected function casts(): array
    {
        return ['surfaced_at' => 'date:Y-m-d'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'assigned_agent_id');
    }
}
