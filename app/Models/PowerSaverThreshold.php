<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Services\PowerSaver\ThresholdSemantics;
use Database\Factories\PowerSaverThresholdFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A stored Power Saver stop percent for one project or one agent routine in a workspace.
 * Absence of a row means no threshold. This model never changes the project or the routine.
 */
class PowerSaverThreshold extends Model
{
    /** @use HasFactory<PowerSaverThresholdFactory> */
    use BelongsToWorkspace, HasFactory;

    public const TYPE_PROJECT = 'project';

    public const TYPE_ROUTINE = 'routine';

    /** Usage ids that are not schedulable routines. `unmatched` is ad-hoc; `other_est_tokens` is the leftover bucket. */
    public const EXCLUDED_ROUTINE_IDS = ['unmatched', 'other_est_tokens'];

    protected $fillable = [
        'subject_type', 'project_id', 'agent_id', 'routine_id', 'stop_percent', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'stop_percent' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PowerSaverThreshold $row): void {
            $row->assertShape();
            $row->identity = self::identityFor(
                (string) $row->subject_type,
                $row->project_id,
                $row->agent_id,
                $row->routine_id,
            );
        });
    }

    public static function isExcludedRoutine(string $routineId): bool
    {
        return in_array($routineId, self::EXCLUDED_ROUTINE_IDS, true);
    }

    public static function identityFor(string $type, ?string $projectId, ?string $agentId, ?string $routineId): string
    {
        $material = $type === self::TYPE_PROJECT
            ? 'project|'.$projectId
            : 'routine|'.$agentId.'|'.$routineId;

        return hash('sha256', $material);
    }

    public function assertShape(): void
    {
        $percent = $this->stop_percent;
        if (! is_int($percent) || $percent < ThresholdSemantics::MIN || $percent > ThresholdSemantics::MAX) {
            throw new InvalidArgumentException('stop_percent must be an integer from 0 to 100.');
        }

        if ($this->subject_type === self::TYPE_PROJECT) {
            if (! is_string($this->project_id) || $this->project_id === '' || $this->agent_id !== null || $this->routine_id !== null) {
                throw new InvalidArgumentException('A project threshold needs a project id and no routine identity.');
            }

            return;
        }

        if ($this->subject_type === self::TYPE_ROUTINE) {
            if ($this->project_id !== null || ! is_string($this->agent_id) || $this->agent_id === '' || ! is_string($this->routine_id) || $this->routine_id === '') {
                throw new InvalidArgumentException('A routine threshold needs an agent id and a routine id.');
            }

            return;
        }

        throw new InvalidArgumentException('Unknown Power Saver subject.');
    }
}
