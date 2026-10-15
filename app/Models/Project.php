<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'slug', 'owner_agent_id', 'archived_at', 'reopened_at'];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'owner_agent_id');
    }

    public function statusUpdates(): HasMany
    {
        return $this->hasMany(StatusUpdate::class);
    }

    public function requiredInputs(): HasMany
    {
        return $this->hasMany(RequiredInput::class);
    }

    public function nextSteps(): HasMany
    {
        return $this->hasMany(NextStep::class);
    }
}
