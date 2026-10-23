<?php

namespace Tests\Unit;

use App\Services\Usage\UsageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** "Yesterday" = today's Europe/Ljubljana date minus one calendar day; the day boundary is Ljubljana midnight, not UTC. */
class UsageYesterdayRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function normalize_range_accepts_7_30_and_yesterday_only(): void
    {
        $this->assertSame(UsageReport::YESTERDAY, UsageReport::normalizeRange('yesterday'));
        $this->assertSame(7, UsageReport::normalizeRange('7'));
        $this->assertSame(7, UsageReport::normalizeRange(7));
        $this->assertSame(30, UsageReport::normalizeRange('30'));
        $this->assertSame(30, UsageReport::normalizeRange(30));
        foreach ([null, '', '1', '14', 'Yesterday', 'yesterday ', ' yesterday', 'YESTERDAY', '7.0', 7.0, true, ['yesterday'], ['30']] as $bad) {
            $this->assertSame(7, UsageReport::normalizeRange($bad), var_export($bad, true));
        }
        $this->assertSame([UsageReport::YESTERDAY, 7, 30], UsageReport::RANGES);
    }

    /** @return array<string, array{string, string, string}> [now, its timezone, expected yesterday] */
    public static function clocks(): array
    {
        return [
            'just after Ljubljana midnight (22:30 UTC the day before)' => ['2026-10-20 00:30:00', 'Europe/Ljubljana', '2026-10-19'],
            'late evening Ljubljana (21:30 UTC same day)' => ['2026-10-19 23:30:00', 'Europe/Ljubljana', '2026-10-18'],
            'app clock given in UTC, already 20 Oct in Ljubljana' => ['2026-10-19 22:30:00', 'UTC', '2026-10-19'],
            'app clock given in UTC, still 19 Oct in Ljubljana' => ['2026-10-19 21:59:59', 'UTC', '2026-10-18'],
            'day after DST end (25 Oct has 25 h)' => ['2026-10-26 00:30:00', 'Europe/Ljubljana', '2026-10-25'],
            'day after DST start (29 Mar has 23 h)' => ['2026-03-30 00:30:00', 'Europe/Ljubljana', '2026-03-29'],
            'month and year boundary' => ['2027-01-01 00:10:00', 'Europe/Ljubljana', '2026-12-31'],
        ];
    }

    #[Test]
    #[DataProvider('clocks')]
    public function yesterday_is_the_previous_ljubljana_calendar_day(string $now, string $tz, string $expected): void
    {
        Carbon::setTestNow(Carbon::parse($now, $tz));
        $this->withSession(['bridge_gate_unlocked' => true]);

        $this->get('/usage?range=yesterday')->assertOk()->assertInertia(fn ($page) => $page
            ->where('range', 'yesterday')
            ->where('from', $expected)
            ->where('to', $expected)
            ->has('days', 1)
            ->where('days.0.date', $expected)
            ->where('days.0.today', false)
            ->where('table.day', $expected));

        // Same answer straight from the report, whatever the PHP default timezone is.
        $default = date_default_timezone_get();
        try {
            foreach (['UTC', 'America/Los_Angeles', 'Pacific/Kiritimati'] as $phpTz) {
                date_default_timezone_set($phpTz);
                $props = (new UsageReport)->overview(UsageReport::YESTERDAY);
                $this->assertSame([$expected, $expected], [$props['from'], $props['to']], $phpTz);
            }
        } finally {
            date_default_timezone_set($default);
        }
    }
}
