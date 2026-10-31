<?php

namespace App\Services\Usage;

use App\Models\CliUsageDay;
use App\Models\CliUsageImport;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * Stores one validated collector object (CliUsageValidator) as cli_usage_days rows, replaced per (workspace, date,
 * tool) for the tools the file covers; other tools' rows of that date stay untouched, so a separate Cursor file does
 * not wipe the collector's rows and vice versa. Covered tools = `covered_tools` when given, else the tools in the
 * rows, plus CliUsageDay::LEGACY_COLLECTOR_TOOLS when no row is Cursor (cli_usage.py files, also empty ones, replace
 * Claude Code / Kimi Code / OpenCode Go as before). Each covered tool gets a CliUsageImport marker (also with zero
 * rows). One row per (date, tool, agent, model); a null or absent `model` is stored as CliUsageDay::NO_MODEL. A day
 * before the collector's coverage_from is skipped untouched: the CLIs delete old transcripts, so such a file is cut
 * short and must never overwrite a complete day imported earlier. Shared by UsageDayImporter (nested `cli_usage`
 * key, same transaction) and `php artisan bridge:cli-usage-import` (standalone files, backfill).
 */
final class CliUsageImporter
{
    public const SKIP_BEFORE_COVERAGE = 'before coverage';

    public function __construct(private readonly CliUsageValidator $validator) {}

    /**
     * Validate a raw collector file, then import it.
     *
     * @return array{date: string, coverage_from: string, rows: int, total: int, skipped: string|null, created: bool}
     *
     * @throws InvalidUsageDay
     */
    public function importRaw(string $raw, bool $dryRun = false): array
    {
        return $this->import($this->validator->validate($raw), $dryRun);
    }

    /**
     * The tools a validated collector object is authoritative for on its date (see the class comment), in TOOLS order.
     *
     * @param  array<string, mixed>  $cli
     * @return list<string>
     */
    public static function coveredTools(array $cli): array
    {
        $tools = $cli['covered_tools'] ?? null;
        if ($tools === null) {
            $tools = array_column($cli['tools'], 'tool');
            if (! in_array('cursor', $tools, true)) {
                $tools = [...$tools, ...CliUsageDay::LEGACY_COLLECTOR_TOOLS];
            }
        }

        return array_values(array_intersect(CliUsageDay::TOOLS, $tools));
    }

    /**
     * `created`: no file had covered any of the covered tools on this date before (no marker).
     *
     * @param  array<string, mixed>  $cli  a collector object that passed CliUsageValidator
     * @return array{date: string, coverage_from: string, rows: int, total: int, skipped: string|null, created: bool}
     */
    public function import(array $cli, bool $dryRun = false): array
    {
        $date = $cli['date'];
        $covered = self::coveredTools($cli);
        $workspaceId = app(CurrentWorkspace::class)->idForWrite(CliUsageDay::class);
        $markers = fn () => CliUsageImport::acrossWorkspaces()
            ->where('workspace_id', $workspaceId)->where('date', $date)->whereIn('tool', $covered);
        $totals = array_fill_keys($covered, ['rows' => 0, 'total' => 0]);
        foreach ($cli['tools'] as $r) {
            $totals[$r['tool']]['rows']++;
            $totals[$r['tool']]['total'] += $r['input'] + $r['output'] + $r['cache_read'] + $r['cache_creation'];
        }
        $result = [
            'date' => $date,
            'coverage_from' => $cli['coverage_from'],
            'rows' => count($cli['tools']),
            'total' => array_sum(array_column($totals, 'total')),
            'skipped' => strcmp($date, $cli['coverage_from']) < 0 ? self::SKIP_BEFORE_COVERAGE : null,
            'created' => ! $markers()->exists(),
        ];

        if ($dryRun || $result['skipped'] !== null) {
            return $result;
        }

        $result['created'] = DB::transaction(function () use ($cli, $date, $covered, $workspaceId, $markers, $totals) {
            $created = $markers()->lockForUpdate()->count() === 0;
            CliUsageDay::acrossWorkspaces()
                ->where('workspace_id', $workspaceId)->where('date', $date)->whereIn('tool', $covered)
                ->delete();
            $now = now();
            // Bulk insert skips the model's creating hook: the workspace is set here.
            $rows = array_map(fn ($r) => [
                'workspace_id' => $workspaceId,
                'date' => $date,
                'tool' => $r['tool'],
                'agent_id' => $r['agent_id'],
                'agent_name' => $r['name'],
                'model' => $r['model'] ?? CliUsageDay::NO_MODEL,
                'input_tokens' => $r['input'],
                'output_tokens' => $r['output'],
                'cache_read_tokens' => $r['cache_read'],
                'cache_creation_tokens' => $r['cache_creation'],
                'calls' => $r['calls'],
                'cost_cents' => $r['cost_cents'] ?? null,
                'max_mode_calls' => $r['max_mode_calls'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $cli['tools']);
            foreach (array_chunk($rows, 500) as $chunk) {
                CliUsageDay::query()->insert($chunk);
            }
            if ($covered !== []) {
                CliUsageImport::acrossWorkspaces()->upsert(array_map(fn ($tool) => [
                    'workspace_id' => $workspaceId,
                    'date' => $date,
                    'tool' => $tool,
                    'rows' => $totals[$tool]['rows'],
                    'total' => $totals[$tool]['total'],
                    'imported_at' => $now,
                ], $covered), ['workspace_id', 'date', 'tool'], ['rows', 'total', 'imported_at']);
            }

            return $created;
        });

        return $result;
    }
}
