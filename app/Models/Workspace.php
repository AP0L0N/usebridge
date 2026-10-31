<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One person's Bridge: projects, agents, boards, usage (docs/MULTI-USER-PLAN.md). Owned rows carry workspace_id
 * (Concerns\BelongsToWorkspace). `features` holds per-workspace switches, e.g. {"polymarket": true}.
 */
class Workspace extends Model
{
    public const ROLES = ['owner', 'admin', 'member'];

    public const FEATURE_POLYMARKET = 'polymarket';

    protected $fillable = ['slug', 'name', 'owner_user_id', 'features'];

    protected function casts(): array
    {
        return ['features' => 'array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    public function hasFeature(string $feature): bool
    {
        return ($this->features[$feature] ?? false) === true;
    }

    public function hasMember(User $user): bool
    {
        return $this->owner_user_id === $user->id || $this->users()->whereKey($user->id)->exists();
    }
}
