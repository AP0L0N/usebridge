<?php

namespace App\Services\PowerSaver;

use App\Models\Agent;
use App\Models\PowerSaverThreshold;
use App\Models\Project;
use App\Models\UsageDay;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lists projects and agent routines with their stop percents, and writes those percents.
 * A project's listed stop_percent is the effective one (0 while archived); saved_stop_percent is the stored row.
 * Reads projects and usage days. Writes only power_saver_thresholds. Never archives, never sets status,
 * never enables or disables a subject.
 */
final class PowerSaverCatalog
{
    /** @var array<string, array{agent_id: string, agent_name: string, routine_id: string, name: string}>|null */
    private ?array $seen = null;

    /**
     * Captain's read shape. Snake case, same as the rest of /api. Semantics are locked.
     *
     * @return array<string, mixed>
     */
    public function apiDocument(): array
    {
        return [
            'semantics' => ThresholdSemantics::toArray(),
            'gaps' => ThresholdSemantics::gaps(),
            'projects' => $this->projects(),
            'routines' => $this->routines(),
        ];
    }

    /**
     * Inertia props. Same facts as the API, camelCase like the other Bridge pages.
     * Archived projects are left out: nobody works on them, and their effective stop is 0 until unarchived.
     *
     * @return array<string, mixed>
     */
    public function pageProps(): array
    {
        $api = $this->apiDocument();

        return [
            'semantics' => $this->camelSemantics($api['semantics']),
            'gaps' => $this->camelGaps($api['gaps']),
            'projects' => array_values(array_map(
                $this->camelProject(...),
                array_filter($api['projects'], fn (array $project): bool => ! $project['archived']),
            )),
            'routines' => array_map($this->camelRoutine(...), $api['routines']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function save(User $actor, array $items): array
    {
        return DB::transaction(function () use ($actor, $items): array {
            $saved = [];
            foreach ($items as $index => $item) {
                $saved[] = $this->saveOne($actor, $item, (int) $index);
            }

            return $saved;
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function saveOne(User $actor, array $item, int $index): array
    {
        $type = $item['subject_type'];
        $percent = $item['stop_percent'] ?? null;
        if ($percent !== null && ! is_int($percent)) {
            throw ValidationException::withMessages([
                "thresholds.$index.stop_percent" => __('power.errors.percent'),
            ]);
        }

        if ($type === PowerSaverThreshold::TYPE_PROJECT) {
            $projectId = (string) $item['project_id'];
            $project = Project::query()->find($projectId);
            if (! $project instanceof Project) {
                throw ValidationException::withMessages([
                    "thresholds.$index.project_id" => __('power.errors.unknown_project'),
                ]);
            }
            $identity = PowerSaverThreshold::identityFor(PowerSaverThreshold::TYPE_PROJECT, $projectId, null, null);
            $this->persist($actor, $identity, [
                'subject_type' => PowerSaverThreshold::TYPE_PROJECT,
                'project_id' => $projectId,
                'agent_id' => null,
                'routine_id' => null,
            ], $percent);

            // stop_percent here is the saved value just written. GET reports the effective one (0 while archived).
            return [
                'subject_type' => PowerSaverThreshold::TYPE_PROJECT,
                'project_id' => $projectId,
                'stop_percent' => $percent,
                'saved_stop_percent' => $percent,
                'archived' => $project->isArchived(),
                'effective_stop_percent' => ThresholdSemantics::effectiveStopPercent($percent, $project->isArchived()),
                'threshold_updated_at' => $this->updatedAt($identity),
            ];
        }

        $agentId = (string) $item['agent_id'];
        $routineId = (string) $item['routine_id'];
        if (PowerSaverThreshold::isExcludedRoutine($routineId)) {
            throw ValidationException::withMessages([
                "thresholds.$index.routine_id" => __('power.errors.excluded_routine'),
            ]);
        }
        if (! $this->routineCanBeStored($agentId, $routineId)) {
            throw ValidationException::withMessages([
                "thresholds.$index.routine_id" => __('power.errors.unknown_routine'),
            ]);
        }
        $identity = PowerSaverThreshold::identityFor(PowerSaverThreshold::TYPE_ROUTINE, null, $agentId, $routineId);
        $this->persist($actor, $identity, [
            'subject_type' => PowerSaverThreshold::TYPE_ROUTINE,
            'project_id' => null,
            'agent_id' => $agentId,
            'routine_id' => $routineId,
        ], $percent);

        return [
            'subject_type' => PowerSaverThreshold::TYPE_ROUTINE,
            'agent_id' => $agentId,
            'routine_id' => $routineId,
            'stop_percent' => $percent,
            'seen_in_usage' => isset($this->seenRoutines()[$this->pairKey($agentId, $routineId)]),
            'threshold_updated_at' => $this->updatedAt($identity),
        ];
    }

    /**
     * @param  array{subject_type: string, project_id: ?string, agent_id: ?string, routine_id: ?string}  $columns
     */
    private function persist(User $actor, string $identity, array $columns, ?int $percent): void
    {
        if ($percent === null) {
            PowerSaverThreshold::query()->where('identity', $identity)->delete();

            return;
        }

        PowerSaverThreshold::query()->updateOrCreate(
            ['identity' => $identity],
            [...$columns, 'stop_percent' => $percent, 'updated_by_user_id' => $actor->id],
        );
    }

    private function updatedAt(string $identity): ?string
    {
        $row = PowerSaverThreshold::query()->where('identity', $identity)->first();

        return $row?->updated_at?->toIso8601String();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projects(): array
    {
        $stored = PowerSaverThreshold::query()
            ->where('subject_type', PowerSaverThreshold::TYPE_PROJECT)
            ->get()
            ->keyBy('project_id');

        $projects = Project::query()->orderBy('name')->orderBy('id')->get();
        $agents = Agent::query()->get()->keyBy('id');
        $idsByAgent = [];
        foreach ($projects as $project) {
            if (! is_string($project->owner_agent_id) || $project->owner_agent_id === '') {
                continue;
            }
            $idsByAgent[$project->owner_agent_id][] = $project->id;
        }
        foreach ($idsByAgent as &$ids) {
            sort($ids);
        }
        unset($ids);

        return $projects->map(function (Project $project) use ($stored, $agents, $idsByAgent): array {
            /** @var PowerSaverThreshold|null $row */
            $row = $stored->get($project->id);
            $agentId = is_string($project->owner_agent_id) && $project->owner_agent_id !== '' ? $project->owner_agent_id : null;
            $agent = $agentId === null ? null : $agents->get($agentId);
            $archived = $project->isArchived();
            $saved = $row?->stop_percent;

            return [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => $project->status,
                'status_label' => Project::statusLabel($project->status),
                'archived' => $archived,
                // PM (projects.owner_agent_id). At most one. Null when the project has no PM.
                'agent_id' => $agentId,
                // Same PM as a list so a caller can walk mappings. Today: [] or [agent_id].
                'agent_ids' => $agentId === null ? [] : [$agentId],
                'agent_name' => $agent?->name ?? $agentId,
                // Every project this PM owns here, including this one, sorted by id. Usage is not split across them.
                'project_ids_for_agent' => $agentId === null ? [] : $idsByAgent[$agentId],
                'split_usage' => false,
                'usage_signal' => 'agent_total_estimated',
                'usage_approximation' => true,
                // Effective: what enforcement compares against. 0 while archived, else the saved percent.
                'stop_percent' => ThresholdSemantics::effectiveStopPercent($saved, $archived),
                // The stored row (null when none). Kept through archive; applies again after unarchive.
                'saved_stop_percent' => $saved,
                'stoppable_in_bridge' => false,
                'threshold_updated_at' => $row?->updated_at?->toIso8601String(),
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function routines(): array
    {
        $agents = Agent::query()->get()->keyBy('id');
        $stored = PowerSaverThreshold::query()
            ->where('subject_type', PowerSaverThreshold::TYPE_ROUTINE)
            ->get()
            ->keyBy(fn (PowerSaverThreshold $row): string => $this->pairKey((string) $row->agent_id, (string) $row->routine_id));

        $rows = $this->seenRoutines();
        foreach ($stored as $key => $row) {
            if (isset($rows[$key])) {
                continue;
            }
            $agent = $agents->get($row->agent_id);
            $rows[$key] = [
                'agent_id' => (string) $row->agent_id,
                'agent_name' => $agent->name ?? (string) $row->agent_id,
                'routine_id' => (string) $row->routine_id,
                'name' => (string) $row->routine_id,
            ];
        }

        $out = [];
        foreach ($rows as $key => $row) {
            $agent = $agents->get($row['agent_id']);
            if ($agent !== null) {
                $row['agent_name'] = $agent->name;
            }
            /** @var PowerSaverThreshold|null $saved */
            $saved = $stored->get($key);
            $out[] = [
                'agent_id' => $row['agent_id'],
                'agent_name' => $row['agent_name'],
                'routine_id' => $row['routine_id'],
                'name' => $row['name'],
                'seen_in_usage' => isset($this->seenRoutines()[$key]),
                'usage_signal' => 'own_routine',
                'unobserved_current_usage' => 0,
                'stop_percent' => $saved?->stop_percent,
                'stoppable_in_bridge' => false,
                'threshold_updated_at' => $saved?->updated_at?->toIso8601String(),
            ];
        }

        usort($out, function (array $a, array $b): int {
            return strcasecmp($a['agent_name'], $b['agent_name'])
                ?: strcasecmp($a['name'], $b['name'])
                ?: strcmp($a['agent_id'], $b['agent_id'])
                ?: strcmp($a['routine_id'], $b['routine_id']);
        });

        return $out;
    }

    private function routineCanBeStored(string $agentId, string $routineId): bool
    {
        if (isset($this->seenRoutines()[$this->pairKey($agentId, $routineId)])) {
            return true;
        }

        return Agent::query()->whereKey($agentId)->exists();
    }

    private function pairKey(string $agentId, string $routineId): string
    {
        return $agentId."\0".$routineId;
    }

    /**
     * Distinct non-excluded routines across this workspace's usage days. Later days win the display name.
     *
     * @return array<string, array{agent_id: string, agent_name: string, routine_id: string, name: string}>
     */
    private function seenRoutines(): array
    {
        if ($this->seen !== null) {
            return $this->seen;
        }

        $seen = [];
        foreach (UsageDay::query()->orderBy('date')->get(['payload']) as $day) {
            $payload = $day->payload;
            if (! is_array($payload)) {
                continue;
            }
            foreach ($payload['agents'] ?? [] as $agent) {
                if (! is_array($agent) || ! is_string($agent['agent_id'] ?? null) || $agent['agent_id'] === '') {
                    continue;
                }
                $agentName = is_string($agent['name'] ?? null) && $agent['name'] !== '' ? $agent['name'] : $agent['agent_id'];
                foreach ($agent['by_routine'] ?? [] as $routine) {
                    if (! is_array($routine) || ! is_string($routine['routine_id'] ?? null) || $routine['routine_id'] === '') {
                        continue;
                    }
                    if (PowerSaverThreshold::isExcludedRoutine($routine['routine_id'])) {
                        continue;
                    }
                    $name = is_string($routine['name'] ?? null) && $routine['name'] !== '' ? $routine['name'] : $routine['routine_id'];
                    $seen[$this->pairKey($agent['agent_id'], $routine['routine_id'])] = [
                        'agent_id' => $agent['agent_id'],
                        'agent_name' => $agentName,
                        'routine_id' => $routine['routine_id'],
                        'name' => $name,
                    ];
                }
            }
        }

        return $this->seen = $seen;
    }

    /**
     * @param  array<string, mixed>  $semantics
     * @return array<string, mixed>
     */
    private function camelSemantics(array $semantics): array
    {
        return $this->camelKeys($semantics);
    }

    /**
     * @param  array<string, mixed>  $gaps
     * @return array<string, mixed>
     */
    private function camelGaps(array $gaps): array
    {
        return $this->camelKeys($gaps);
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function camelKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->camelKeys(...), $value);
        }
        $out = [];
        foreach ($value as $key => $item) {
            $camel = lcfirst(str_replace('_', '', ucwords((string) $key, '_')));
            $out[$camel] = $this->camelKeys($item);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $project
     * @return array<string, mixed>
     */
    private function camelProject(array $project): array
    {
        return [
            'id' => $project['id'],
            'name' => $project['name'],
            'slug' => $project['slug'],
            'status' => $project['status'],
            'statusLabel' => $project['status_label'],
            'archived' => $project['archived'],
            'agentId' => $project['agent_id'],
            'agentIds' => $project['agent_ids'],
            'agentName' => $project['agent_name'],
            'projectIdsForAgent' => $project['project_ids_for_agent'],
            'splitUsage' => $project['split_usage'],
            'usageSignal' => $project['usage_signal'],
            'usageApproximation' => $project['usage_approximation'],
            'stopPercent' => $project['stop_percent'],
            'stoppableInBridge' => $project['stoppable_in_bridge'],
            'thresholdUpdatedAt' => $project['threshold_updated_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $routine
     * @return array<string, mixed>
     */
    private function camelRoutine(array $routine): array
    {
        return [
            'agentId' => $routine['agent_id'],
            'agentName' => $routine['agent_name'],
            'routineId' => $routine['routine_id'],
            'name' => $routine['name'],
            'seenInUsage' => $routine['seen_in_usage'],
            'usageSignal' => $routine['usage_signal'],
            'unobservedCurrentUsage' => $routine['unobserved_current_usage'],
            'stopPercent' => $routine['stop_percent'],
            'stoppableInBridge' => $routine['stoppable_in_bridge'],
            'thresholdUpdatedAt' => $routine['threshold_updated_at'],
        ];
    }
}
