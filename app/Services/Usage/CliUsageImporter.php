<?php

namespace App\Services\Usage;

use App\Models\CliUsageDay;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * Stores one validated collector object (CliUsageValidator) as cli_usage_days rows: the day's rows are
 * replaced as a whole (idempotent per date; one row per (date, tool, agent, model); a null or absent
 * `model` is stored as CliUsageDay::NO_MODEL). A day before the collector's
 * coverage_from is skipped untouched: the CLIs delete old transcripts, so such a file is cut short and must
 * never overwrite a complete day imported earlier. Shared by UsageDayImporter (nested `cli_usage` key, same
 * transaction) and `php artisan bridge:cli-usage-import` (standalone files, backfill).
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
     * @param  array<string, mixed>  $cli  a collector object that passed CliUsageValidator
     * @return array{date: string, coverage_from: string, rows: int, total: int, skipped: string|null, created: bool}
     */
    public function import(array $cli, bool $dryRun = false): array
    {
        $date = $cli['date'];
        $result = [
            'date' => $date,
            'coverage_from' => $cli['coverage_from'],
            'rows' => count($cli['tools']),
            'total' => array_sum(array_map(fn ($r) => $r['input'] + $r['output'] + $r['cache_read'] + $r['cache_creation'], $cli['tools'])),
            'skipped' => strcmp($date, $cli['coverage_from']) < 0 ? self::SKIP_BEFORE_COVERAGE : null,
            'created' => ! CliUsageDay::query()->where('date', $date)->exists(),
        ];

        if ($dryRun || $result['skipped'] !== null) {
            return $result;
        }

        $result['created'] = DB::transaction(function () use ($cli, $date) {
            $created = CliUsageDay::query()->where('date', $date)->lockForUpdate()->count() === 0;
            CliUsageDay::query()->where('date', $date)->delete();
            $now = now();
            // Bulk insert skips the model's creating hook: the workspace is set here.
            $workspaceId = app(CurrentWorkspace::class)->idForWrite(CliUsageDay::class);
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
                'created_at' => $now,
                'updated_at' => $now,
            ], $cli['tools']);
            foreach (array_chunk($rows, 500) as $chunk) {
                CliUsageDay::query()->insert($chunk);
            }

            return $created;
        });

        return $result;
    }
}
