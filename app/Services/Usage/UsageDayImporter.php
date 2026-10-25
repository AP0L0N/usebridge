<?php

namespace App\Services\Usage;

use App\Models\UsageDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Validate + upsert one day file by `date`. Shared by POST /api/usage/days and
 * `php artisan bridge:usage-import`. Re-posting a date replaces the row.
 *
 * A file may carry the collector's measured Claude Code / Kimi Code tokens under `cli_usage` (null or an
 * object, see CliUsageValidator). That object is stripped from the stored payload (its rows live in
 * cli_usage_days and must not leak into the estimate-side agent scans) and handed to CliUsageImporter in
 * the same transaction. Files without the key import exactly as before.
 */
final class UsageDayImporter
{
    public function __construct(private readonly UsageDayValidator $validator, private readonly CliUsageImporter $cli) {}

    /**
     * @return array{date: string, est_tokens_total: int, agents: int, created: bool, cli?: array<string, mixed>}
     *   `cli` only when the file carries a cli_usage object: CliUsageImporter's result.
     *
     * @throws InvalidUsageDay
     */
    public function import(string $raw, bool $dryRun = false): array
    {
        $day = $this->validator->validate($raw);
        $cli = $day['cli_usage'] ?? null;
        unset($day['cli_usage']);

        $result = [
            'date' => $day['date'],
            'est_tokens_total' => $day['est_tokens_total'],
            'agents' => count($day['agents']),
            'created' => ! UsageDay::query()->where('date', $day['date'])->exists(),
        ];

        if ($dryRun) {
            if (is_array($cli)) {
                $result['cli'] = $this->cli->import($cli, true);
            }

            return $result;
        }

        [$result['created'], $cliResult] = DB::transaction(function () use ($day, $cli) {
            $row = UsageDay::query()->where('date', $day['date'])->lockForUpdate()->first() ?? new UsageDay(['date' => $day['date']]);
            $created = ! $row->exists;
            $row->fill([
                'generated_at' => CarbonImmutable::parse($day['generated_at']),
                'est_tokens_total' => $day['est_tokens_total'],
                'payload' => $day,
            ])->save();

            return [$created, is_array($cli) ? $this->cli->import($cli) : null];
        });
        if ($cliResult !== null) {
            $result['cli'] = $cliResult;
        }

        return $result;
    }
}
