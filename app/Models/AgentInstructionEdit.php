<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved revision of an agent's description, proposed by the workspace owner in Bridge. Saving does not change
 * the agent: the daily sync applies a pending edit to the real profile and reports back (docs/agent-instructions-sync.md).
 * Lifecycle: pending -> applied | rejected | conflict | superseded. At most one pending edit per agent.
 */
class AgentInstructionEdit extends Model
{
    use BelongsToWorkspace;

    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    public const REJECTED = 'rejected';

    public const CONFLICT = 'conflict';

    public const SUPERSEDED = 'superseded';

    public const STATUSES = [self::PENDING, self::APPLIED, self::REJECTED, self::CONFLICT, self::SUPERSEDED];

    public const CONFLICT_REASON = 'profile description changed after this edit was made';

    protected $fillable = [
        'agent_id', 'revision', 'base_hash', 'base_description', 'proposed_description', 'author_user_id', 'author',
        'status', 'status_reason', 'applied_hash', 'applied_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'applied_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function proposedHash(): string
    {
        return AgentProfile::hashOf($this->proposed_description);
    }

    /** Leave pending for a final state (applied, rejected, conflict, superseded). */
    public function resolve(string $status, ?string $reason = null, array $extra = []): void
    {
        $this->forceFill([...$extra, 'status' => $status, 'status_reason' => $reason, 'resolved_at' => now()])->save();
    }
}
