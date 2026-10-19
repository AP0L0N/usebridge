<?php

namespace App\Services\Usage;

use App\Models\UsageDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Validate + upsert one day file by `date`. Shared by POST /api/usage/days and
 * `php artisan bridge:usage-import`. Re-posting a date replaces the row.
 */
final class UsageDayImporter
{
    public function __construct(private readonly UsageDayValidator $validator) {}

    /**
     * @return array{date: string, est_tokens_total: int, agents: int, created: bool}
     *
     * @throws InvalidUsageDay
     */
    public function import(string $raw, bool $dryRun = false): array
    {
        $day = $this->validator->validate($raw);

        $result = [
            'date' => $day['date'],
            'est_tokens_total' => $day['est_tokens_total'],
            'agents' => count($day['agents']),
            'created' => ! UsageDay::query()->where('date', $day['date'])->exists(),
        ];

        if ($dryRun) {
            return $result;
        }

        $result['created'] = DB::transaction(function () use ($day) {
            $row = UsageDay::query()->where('date', $day['date'])->lockForUpdate()->first() ?? new UsageDay(['date' => $day['date']]);
            $created = ! $row->exists;
            $row->fill([
                'generated_at' => CarbonImmutable::parse($day['generated_at']),
                'est_tokens_total' => $day['est_tokens_total'],
                'payload' => $day,
            ])->save();

            return $created;
        });

        return $result;
    }
}
