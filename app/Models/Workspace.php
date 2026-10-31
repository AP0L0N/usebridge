<?php

namespace App\Models;

use App\Services\Tickets\HumanActors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One person's Bridge: projects, agents, boards, usage (docs/MULTI-USER-PLAN.md). Owned rows carry workspace_id
 * (Concerns\BelongsToWorkspace). `features` holds per-workspace switches, e.g. {"polymarket": true}. `settings` holds
 * per-workspace values, e.g. {"waiting_label": "…"} (the waiting_tilen column name; BoardColumns::defaults).
 */
class Workspace extends Model
{
    public const ROLES = ['owner', 'admin', 'member'];

    public const FEATURE_POLYMARKET = 'polymarket';

    /** POST /api/usage/days from a collector (the default workspace owner's box): read & write tokens get usage:write only with this. */
    public const FEATURE_USAGE_UPLOAD = 'usage_upload';

    public const SETTING_WAITING_LABEL = 'waiting_label';

    protected $fillable = ['slug', 'name', 'owner_user_id', 'features', 'settings'];

    protected function casts(): array
    {
        return ['features' => 'array', 'settings' => 'array'];
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

    /** This workspace's own stored name for the waiting_tilen column; null = the default (waitingColumnLabel). */
    public function waitingLabel(): ?string
    {
        $label = $this->settings[self::SETTING_WAITING_LABEL] ?? null;

        return is_string($label) && trim($label) !== '' ? $label : null;
    }

    /** The waiting_tilen column's name: settings.waiting_label, else "Waiting on <owner's first name>" ("Waiting on you"). */
    public function waitingColumnLabel(): string
    {
        return $this->waitingLabel() ?? HumanActors::defaultWaitingLabel($this);
    }

    /** The workspace owner (owner_user_id), e.g. for owner-only features like Polymarket. */
    public function isOwnedBy(User $user): bool
    {
        return $this->owner_user_id !== null && (int) $this->owner_user_id === (int) $user->id;
    }

    public function hasMember(User $user): bool
    {
        return $this->owner_user_id === $user->id || $this->users()->whereKey($user->id)->exists();
    }
}
