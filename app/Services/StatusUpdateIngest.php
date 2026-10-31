<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Project;
use App\Models\RequiredInput;
use App\Models\StatusUpdate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One write path for status updates (API + web).
 * - Creates the update (same contract as r0: project_id, agent_id, body, optional id).
 * - Optional r1 extras: `inputs` (new open Required inputs) and `resolved_inputs` (mark done).
 * - Archived project -> un-archived + reopened_at = now ("Reopened" chip).
 *   Non-archived project with a Reopened chip -> chip cleared (next update clears it).
 */
class StatusUpdateIngest
{
    /**
     * @param  array<int, array{text:string, waiting_on:string, since?:string|null}>  $inputs
     * @param  array<int, string|array{id:string, resolved_by?:string|null, done_by?:string|null}>  $resolved
     * @return array{update: StatusUpdate, inputs: array<int, RequiredInput>, resolved: array<int, RequiredInput>, unarchived: bool}
     */
    public function handle(Project $project, string $agentId, string $body, ?string $id = null, array $inputs = [], array $resolved = []): array
    {
        return DB::transaction(function () use ($project, $agentId, $body, $id, $inputs, $resolved) {
            $now = Carbon::now();
            $update = StatusUpdate::create([
                'id' => $id ?? 'su-'.Str::lower((string) Str::ulid()),
                'project_id' => $project->id,
                'agent_id' => $agentId,
                'body' => $body,
                'created_at' => $now,
            ]);

            $unarchived = false;
            if ($project->archived_at !== null) {
                $project->archived_at = null;
                $project->reopened_at = $now;
                $unarchived = true;
            } elseif ($project->reopened_at !== null) {
                $project->reopened_at = null;
            }
            $project->touch();
            $project->save();

            $created = [];
            foreach ($inputs as $in) {
                $created[] = RequiredInput::create([
                    'id' => 'in-'.Str::lower((string) Str::ulid()),
                    'project_id' => $project->id,
                    'status_update_id' => $update->id,
                    'text' => $in['text'],
                    'waiting_on' => $in['waiting_on'],
                    'since' => isset($in['since']) ? Carbon::parse($in['since']) : $now,
                ]);
            }

            $agentName = Agent::query()->whereKey($agentId)->value('name') ?? $agentId;
            $done = [];
            foreach ($resolved as $r) {
                $rid = is_array($r) ? $r['id'] : $r;
                $input = RequiredInput::query()->where('project_id', $project->id)->whereKey($rid)->first();
                if ($input && $input->resolved_at === null) {
                    $input->update([
                        'resolved_at' => $now,
                        'resolved_by' => (is_array($r) ? ($r['resolved_by'] ?? $r['done_by'] ?? null) : null) ?? $agentName,
                    ]);
                    $done[] = $input;
                }
            }

            return ['update' => $update, 'inputs' => $created, 'resolved' => $done, 'unarchived' => $unarchived];
        });
    }

    /** Body + extras (POST /api/status-updates and MCP post_status). */
    public static function rules(): array
    {
        return ['body' => ['required', 'string', 'max:5000'], ...self::extraRules()];
    }

    /** Validation rules for the r1 extras (all optional => r0 payloads still valid). */
    public static function extraRules(): array
    {
        return [
            'inputs' => ['sometimes', 'array', 'max:50'],
            'inputs.*.text' => ['required', 'string', 'max:500'],
            'inputs.*.waiting_on' => ['required', 'string', 'max:120'],
            'inputs.*.since' => ['nullable', 'date'],
            'resolved_inputs' => ['sometimes', 'array', 'max:50'],
            'resolved_inputs.*' => ['required'],
            'resolved_inputs.*.id' => ['sometimes', 'string', 'max:64'],
            'resolved_inputs.*.resolved_by' => ['nullable', 'string', 'max:120'],
            'resolved_inputs.*.done_by' => ['nullable', 'string', 'max:120'], // r1 name, still accepted
        ];
    }
}
