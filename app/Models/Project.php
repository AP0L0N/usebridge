<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'name', 'slug', 'owner_agent_id', 'goal', 'phase',
        'phase_done_looks_like', 'next_deadline', 'needs_decision',
    ];

    protected function casts(): array
    {
        return [
            'next_deadline' => 'date:Y-m-d',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'owner_agent_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class);
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class);
    }

    public function statusUpdates(): HasMany
    {
        return $this->hasMany(StatusUpdate::class);
    }
}
