<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use BelongsToWorkspace;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'role_label'];

    public function ownedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'owner_agent_id');
    }
}
