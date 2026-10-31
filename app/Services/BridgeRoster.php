<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Project;
use App\Services\Tickets\Actor;
use App\Support\TicketPrefix;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Roster: agent rows that must exist and the PM of every active project, loaded from the JSON file at
 * BRIDGE_ROSTER_PATH (config bridge.roster.path, kept outside the repo):
 *   {"agents": {"agent-id": ["Name", "Role"]}, "pms": {"project-id": "agent-id"}, "ticket_prefixes": {"project-id": "AB"}}
 * ("ticket_prefixes" is optional, see TicketPrefix). Missing, unreadable or invalid file = empty roster (nothing
 * changes) and one warning log line without the file's contents.
 * Idempotent and additive: missing agents are inserted (existing rows are never modified), and only
 * projects.owner_agent_id (the PM, "pm_agent_id" in the API) changes. Nothing is deleted.
 * Applied by the 2026_10_29_120000 migration on deploy and by `php artisan bridge:set-pms [--dry-run]`.
 */
final class BridgeRoster
{
    /** @var array<string, array{agents: array<string, array{0: string, 1: string}>, pms: array<string, string>, ticket_prefixes: array<string, string>}> */
    private static array $loaded = [];

    /**
     * @return array{agents: array<string, array{0: string, 1: string}>, pms: array<string, string>, ticket_prefixes: array<string, string>}
     */
    public static function load(): array
    {
        $empty = ['agents' => [], 'pms' => [], 'ticket_prefixes' => []];
        $path = config('bridge.roster.path');
        if (! is_string($path) || $path === '') {
            return $empty;
        }
        clearstatcache(true, $path);
        $key = $path.'|'.@filemtime($path).'|'.@filesize($path);
        if (isset(self::$loaded[$key])) {
            return self::$loaded[$key];
        }
        $raw = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $roster = is_array($data) ? self::validated($data) : null;
        if ($roster === null) {
            Log::warning('Bridge roster: BRIDGE_ROSTER_PATH is missing, unreadable or invalid; using an empty roster.');

            return self::$loaded[$key] = $empty;
        }

        return self::$loaded[$key] = $roster;
    }

    /** @return array{agents: array<string, array{0: string, 1: string}>, pms: array<string, string>, ticket_prefixes: array<string, string>}|null */
    private static function validated(array $data): ?array
    {
        $agents = $data['agents'] ?? [];
        $pms = $data['pms'] ?? [];
        $prefixes = $data['ticket_prefixes'] ?? [];
        if (! is_array($agents) || ! is_array($pms) || ! is_array($prefixes)) {
            return null;
        }
        foreach ($agents as $id => $row) {
            if (! is_string($id) || $id === '' || ! is_array($row) || ! array_is_list($row) || count($row) !== 2
                || ! is_string($row[0]) || $row[0] === '' || ! is_string($row[1])) {
                return null;
            }
        }
        foreach ($pms as $project => $agent) {
            if (! is_string($project) || $project === '' || ! is_string($agent) || $agent === '') {
                return null;
            }
        }
        foreach ($prefixes as $project => $prefix) {
            if (! is_string($project) || $project === '' || ! is_string($prefix) || ! preg_match(TicketPrefix::PATTERN, $prefix)) {
                return null;
            }
        }

        return ['agents' => $agents, 'pms' => $pms, 'ticket_prefixes' => $prefixes];
    }

    /**
     * @return array{agents_created: list<string>, agents_kept: list<string>, pms: list<array{project:string,from:?string,to:string,changed:bool}>, missing_projects: list<string>, missing_agents: list<string>}
     */
    public static function apply(bool $dryRun = false): array
    {
        $out = ['agents_created' => [], 'agents_kept' => [], 'pms' => [], 'missing_projects' => [], 'missing_agents' => []];

        $roster = self::load();
        $run = function () use ($dryRun, &$out, $roster) {
            $now = Carbon::now();
            foreach ($roster['agents'] as $id => [$name, $role]) {
                if (Agent::query()->whereKey($id)->exists()) {
                    $out['agents_kept'][] = $id;

                    continue;
                }
                $out['agents_created'][] = $id;
                if (! $dryRun) {
                    Agent::query()->insert(['id' => $id, 'name' => $name, 'role_label' => $role, 'created_at' => $now, 'updated_at' => $now, ...self::workspace('agents')]);
                }
            }
            foreach ($roster['pms'] as $projectId => $pm) {
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

    /**
     * workspace_id for a raw insert (no model hook): the given/current workspace, else the default one. Empty before the
     * multi-user migrations (the 2026_10_29 migration runs this roster on an older schema).
     */
    private static function workspace(string $table, ?int $id = null): array
    {
        if (! Schema::hasColumn($table, 'workspace_id')) {
            return [];
        }

        return ['workspace_id' => $id ?? app(CurrentWorkspace::class)->idForWrite($table)];
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
            ...self::workspace('project_pm_events', $project->workspace_id),
        ]);
        $project->forceFill(['owner_agent_id' => $to])->save();

        return true;
    }
}
