<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Support\BoardColumns;
use App\Support\TicketPrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use BelongsToWorkspace;

    /** PM-set status (PATCH /api/projects/{id}/status); null = "Status not set". */
    public const STATUSES = ['smooth', 'waiting', 'archived'];

    public const STATUS_LABELS = ['smooth' => 'Smooth sailing', 'waiting' => 'Waiting input', 'archived' => 'Archived'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'name', 'slug', 'owner_agent_id', 'archived_at', 'reopened_at',
        'ticket_prefix', 'status', 'status_note', 'status_set_by', 'status_set_at', 'last_activity_at',
        'description', 'column_labels',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'reopened_at' => 'datetime',
            'ticket_seq' => 'integer',
            'status_set_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'column_labels' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Every new project gets a unique ticket key prefix (same generator as the tickets migration). Prefixes are
        // still globally unique (docs/MULTI-USER-PLAN.md §1), so all workspaces count as taken.
        static::creating(function (Project $project) {
            if (blank($project->ticket_prefix)) {
                $project->ticket_prefix = TicketPrefix::forProject((string) $project->id, (string) $project->name, static::acrossWorkspaces()->whereNotNull('ticket_prefix')->pluck('ticket_prefix'));
            }
        });
    }

    /** This project's board columns (own labels over the defaults). @return list<array{key:string,label:string,order:int}> */
    public function boardColumns(): array
    {
        return BoardColumns::all($this->column_labels);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null || $this->status === 'archived';
    }

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? 'Status not set';
    }

    /** Denormalised "last activity" for the portfolio: bumped by every ticket, comment and status write. */
    public static function touchActivity(string $projectId): void
    {
        static::query()->whereKey($projectId)->toBase()->update(['last_activity_at' => now()]);
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

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(ProjectStatusEvent::class);
    }
}
