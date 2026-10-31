<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Login account (docs/MULTI-USER-PLAN.md §2). Verified email before tokens / MCP; disabled_at (admin) and
 * delete_requested_at (7-day grace) refuse logins and block tokens; deleted_at = soft-deleted after the grace.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_DELETION_PENDING = 'deletion_pending';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'failed_logins' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
            'pending_email_at' => 'datetime',
            'delete_requested_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'age_confirmed_at' => 'datetime',
        ];
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_user_id');
    }

    public function status(): string
    {
        return match (true) {
            $this->disabled_at !== null => self::STATUS_DISABLED,
            $this->delete_requested_at !== null => self::STATUS_DELETION_PENDING,
            default => self::STATUS_ACTIVE,
        };
    }

    /** Disabled or deletion pending (or soft-deleted): no login, no web session, tokens answer 403. */
    public function isBlocked(): bool
    {
        return $this->status() !== self::STATUS_ACTIVE || $this->trashed();
    }

    /** The workspace a web session acts in: owned, else the only membership (same rule as tokens without a binding). */
    public function homeWorkspace(): ?Workspace
    {
        $owned = $this->ownedWorkspaces()->orderBy('id')->first();
        if ($owned !== null) {
            return $owned;
        }
        $memberships = $this->workspaces()->limit(2)->get();

        return $memberships->count() === 1 ? $memberships->first() : null;
    }
}
