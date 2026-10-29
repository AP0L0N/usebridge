<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Project;
use App\Services\Tickets\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Captain's roster (29 Oct 2026): agent rows that must exist and the real PM of every active project.
 * Idempotent and additive: missing agents are inserted (existing rows are never modified), and only
 * projects.owner_agent_id (the PM, "pm_agent_id" in the API) changes. Nothing is deleted.
 * Applied by the 2026_10_29_120000 migration on deploy and by `php artisan bridge:set-pms [--dry-run]`.
 */
final class BridgeRoster
{
    /** id => [name, role_label] */
    public const AGENTS = [
        'agent-enrique' => ['Enrique Cortez', 'PM · AdriaEdition'],
        'agent-erin' => ['Erin Bloome', 'Developer'],
        'agent-marc' => ['Marc Miller', 'Agent'],
        'agent-the-many' => ['The Many', 'Deploy'],
        'agent-constance' => ['Constance Sanger', 'QA'],
        'agent-taz' => ['Taz Amanpour', 'Agent'],
        'agent-anatoly' => ['Anatoly Korenchkin', 'Sales owner · Merkwell'],
    ];

    /** project id => PM agent id */
    public const PMS = [
        'proj-chipolo' => 'agent-angela',                     // Chipolo CraftCMS
        'proj-01m4rs9vazyhx6xz37hfmjwea7' => 'agent-watts',   // Chipolo Shopify
        'proj-01m4rs1yd40cnfn1c681sw3ght' => 'agent-enrique', // AdriaEdition
        'proj-concierge-avant' => 'agent-janice',             // Concierge
        'proj-merkwell' => 'agent-anatoly',                   // Merkwell
        'proj-chatpulse' => 'agent-alice',                    // ChatPulse
    ];

    /**
     * @return array{agents_created: list<string>, agents_kept: list<string>, pms: list<array{project:string,from:?string,to:string,changed:bool}>, missing_projects: list<string>, missing_agents: list<string>}
     */
    public static function apply(bool $dryRun = false): array
    {
        $out = ['agents_created' => [], 'agents_kept' => [], 'pms' => [], 'missing_projects' => [], 'missing_agents' => []];

        $run = function () use ($dryRun, &$out) {
            $now = Carbon::now();
            foreach (self::AGENTS as $id => [$name, $role]) {
                if (Agent::query()->whereKey($id)->exists()) {
                    $out['agents_kept'][] = $id;

                    continue;
                }
                $out['agents_created'][] = $id;
                if (! $dryRun) {
                    Agent::query()->insert(['id' => $id, 'name' => $name, 'role_label' => $role, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            foreach (self::PMS as $projectId => $pm) {
                $project = Project::query()->find($projectId);
                if (! $project) {
                    $out['missing_projects'][] = $projectId;

                    continue;
                }
                $agentExists = Agent::query()->whereKey($pm)->exists() || ($dryRun && in_array($pm, $out['agents_created'], true));
                if (! $agentExists) {
                    $out['missing_agents'][] = $pm;

                    continue;
                }
                $from = $project->owner_agent_id;
                $changed = $from !== $pm;
                $out['pms'][] = ['project' => $projectId, 'from' => $from, 'to' => $pm, 'changed' => $changed];
                if ($changed && ! $dryRun) {
                    self::setPm($project, $pm, Actor::MIGRATION, 'migration');
                }
            }
        };

        $dryRun ? $run() : DB::transaction($run);

        return $out;
    }

    /** Change a project's PM and append a project_pm_events row (history). No-op when unchanged. */
    public static function setPm(Project $project, string $to, string $by, string $source): bool
    {
        $from = $project->owner_agent_id;
        if ($from === $to) {
            return false;
        }
        $now = Carbon::now();
        DB::table('project_pm_events')->insert([
            'id' => 'ppe-'.Str::lower((string) Str::ulid()),
            'project_id' => $project->id,
            'from_agent_id' => $from,
            'to_agent_id' => $to,
            'set_by' => $by,
            'source' => $source,
            'created_at' => $now,
        ]);
        $project->forceFill(['owner_agent_id' => $to])->save();

        return true;
    }
}
