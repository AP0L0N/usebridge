<?php

namespace App\Services\Polymarket;

use App\Models\PolymarketSeriesPoint;
use App\Models\PolymarketSnapshot;
use App\Support\OrgDate;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Everything the Polymarket desk page shows (DATA-SPEC r1 §3–§5), built from the stored snapshot and
 * polymarket_series_points. The UI only formats: every derived figure is computed here.
 *
 * - Times are Europe/Ljubljana. Chart points carry their local day + minute of day, so the browser
 *   needs no timezone maths for the session axis.
 * - Book Δ "since yesterday" / "vs 7 days ago" are relative to the snapshot's as_of (the moment the
 *   book value is from): the last run before that day's 00:00, and the last run at or before as_of − 7 days.
 * - Freshness follows Captain's post schedule: one snapshot per weekday at ~19:00, none on weekends;
 *   only weekday posts missed after their 21:00 grace make the feed late (1) or stale (2+).
 * - Fills are the last 7 days before now; redeems & sweeps are listed newest first (the page shows 6).
 * - Both position key styles are read (outcome|side, avg_price|avg_entry, value_usd|current_value_usd);
 *   a position without `settled` is settled when it is redeemable at value 0.
 * - A missing field stays null (the page shows "—" or "Not supplied"); nothing is guessed. A stored
 *   snapshot that cannot be read gives `feed: null` (page-level "feed isn't available").
 */
final class DeskReport
{
    /** Captain posts the snapshot every weekday (Mon–Fri) at ~19:00 local; nothing on weekends. */
    private const POST_HOUR = 19;

    /** A post from this hour on counts for that weekday (an early 18:30 post is that day's post). */
    private const WINDOW_OPENS = 17;

    /** A weekday's post is missed once this hour passes without one. */
    private const GRACE_UNTIL = 21;

    /** How many days back missed posts are counted. */
    private const LOOKBACK_DAYS = 14;

    private CarbonImmutable $now;

    private string $today;

    public function __construct(?CarbonImmutable $now = null)
    {
        $this->now = ($now ?? CarbonImmutable::now())->timezone(OrgDate::TIMEZONE);
        $this->today = $this->now->format('Y-m-d');
    }

    /** @return array{now: string, feed: array<string, mixed>|null} */
    public function props(): array
    {
        $row = PolymarketSnapshot::query()->orderByDesc('as_of')->orderByDesc('id')->first();
        $feed = null;
        if ($row !== null) {
            try {
                $feed = $this->feed($row);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return ['now' => $this->now->toIso8601String(), 'feed' => $feed];
    }

    /** @return array<string, mixed>|null */
    private function feed(PolymarketSnapshot $row): ?array
    {
        $s = $row->payload;
        if (! is_array($s) || ! is_string($s['as_of'] ?? null) || self::num($s['book_value_usd'] ?? null) === null) {
            return null;
        }
        $asOf = CarbonImmutable::parse($s['as_of'])->timezone(OrgDate::TIMEZONE);
        $book = self::num($s['book_value_usd']);

        $points = $this->points();
        $positions = array_map(self::position(...), array_values(array_filter(is_array($s['positions'] ?? null) ? $s['positions'] : [], 'is_array')));
        $open = array_values(array_filter($positions, fn ($p) => ! $p['settled']));
        $settled = array_values(array_filter($positions, fn ($p) => $p['settled']));
        $sweeps = $this->sweeps($s);
        $peak = self::peak($points, $asOf, $book);
        $hw = self::num($s['high_water_usd'] ?? null);
        $funded = self::num($s['funded_usd'] ?? null);
        $openCost = self::sum($open, 'cost');

        return [
            'asOf' => $asOf->toIso8601String(),
            'asOfLabel' => $this->relative($asOf),
            'asOfTime' => $asOf->format('H:i'),
            'freshness' => $this->freshness($asOf),
            'gateway' => self::gateway($s['gateway'] ?? null),
            'lastDecision' => is_string($s['last_decision'] ?? null) && $s['last_decision'] !== '' ? ucfirst(strtolower($s['last_decision'])) : null,
            'stop' => self::stop($points, self::num($s['stop_level_usd'] ?? null)),
            'note' => is_string($s['note'] ?? null) && trim($s['note']) !== '' ? [
                'text' => trim($s['note']),
                'tsLabel' => is_string($s['note_ts'] ?? null) ? $this->relative(CarbonImmutable::parse($s['note_ts'])->timezone(OrgDate::TIMEZONE)) : null,
            ] : null,
            'book' => [
                'value' => $book,
                'deltaYesterday' => self::delta($book, self::lastBefore($points, $asOf->startOfDay())),
                'delta7d' => self::delta($book, self::lastBefore($points, $asOf->subDays(7), inclusive: true)),
            ],
            'totalPnl' => [
                'value' => self::num($s['total_pnl_usd'] ?? null),
                'pct' => ($t = self::num($s['total_pnl_usd'] ?? null)) !== null && $funded ? $t / $funded * 100 : null,
                'swept' => self::num($s['sweeps_total_usd'] ?? null),
                'funded' => $funded,
                'fundedSinceLabel' => self::dayLabel($s['funded_since'] ?? null),
            ],
            'realized' => [
                'value' => self::num($s['realized_pnl_usd'] ?? null),
                'sinceLabel' => self::dayLabel($s['realized_since'] ?? null),
            ],
            'unrealized' => ['value' => self::unrealized($positions), 'count' => count($open)],
            'cash' => self::num($s['cash_usd'] ?? null),
            'openValue' => self::num($s['open_value_usd'] ?? null),
            'openCount' => is_int($s['open_count'] ?? null) ? $s['open_count'] : count($open),
            'openCost' => round($openCost, 2),
            'highWater' => [
                'value' => $hw,
                'below' => $hw ? ($hw - $book) / $hw * 100 : null,
                // "set {date}" only when the highest stored run is the high-water (not when runs are missing).
                'setLabel' => $peak && ($hw === null || abs($peak['book'] - $hw) < 0.01) ? $peak['dayLabel'] : null,
            ],
            'swept' => [
                'value' => self::num($s['sweeps_total_usd'] ?? null),
                'count' => $sweeps['sweepCount'],
                'lastLabel' => $sweeps['lastSweepLabel'],
            ],
            'chart' => $this->chart($points, $asOf, $book, self::num($s['cash_usd'] ?? null), $hw, $funded, $peak, $sweeps['track']),
            'positions' => [
                'open' => $this->withEnds($open),
                'settled' => $this->withEnds($settled),
                'openTotals' => self::totals($open),
                'settledTotals' => self::totals($settled),
            ],
            'fills' => $this->fills($s),
            'sweeps' => [
                'available' => $sweeps['available'],
                'items' => $sweeps['items'],
                'redeemed' => $sweeps['redeemed'],
                'swept' => $sweeps['swept'],
                'recent' => $sweeps['recent'],
                'sinceLabel' => self::dayLabel($s['funded_since'] ?? null),
            ],
            'costBasis' => is_string($s['cost_basis'] ?? null) ? $s['cost_basis'] : null,
            'receivedAt' => CarbonImmutable::createFromFormat('Y-m-d H:i:s', substr((string) $row->received_at, 0, 19), 'UTC')->timezone(OrgDate::TIMEZONE)->toIso8601String(),
        ];
    }

    /**
     * One row of the positions table from either key style.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public static function position(array $p): array
    {
        $cost = self::num($p['cost_usd'] ?? null);
        $value = self::num($p['value_usd'] ?? $p['current_value_usd'] ?? null);
        $pnl = self::num($p['pnl_usd'] ?? null);
        $pct = self::num($p['pnl_pct'] ?? null);
        if ($pct === null && $pnl !== null && $cost) {
            $pct = $pnl / $cost * 100;
        }
        $redeemable = ($p['redeemable'] ?? null) === true;
        $settled = is_bool($p['settled'] ?? null) ? $p['settled'] : ($redeemable && $value !== null && abs($value) < 0.005);
        $outcome = $p['outcome'] ?? $p['side'] ?? null;

        return [
            'market' => is_string($p['market'] ?? null) ? $p['market'] : '',
            'url' => self::marketUrl($p),
            'outcome' => is_string($outcome) && $outcome !== '' ? $outcome : null,
            'shares' => self::num($p['shares'] ?? null),
            'avgPrice' => self::num($p['avg_price'] ?? $p['avg_entry'] ?? null),
            'curPrice' => self::num($p['cur_price'] ?? null),
            'cost' => $cost,
            'value' => $value,
            'pnl' => $pnl,
            'pnlPct' => $pct,
            'endDate' => is_string($p['end_date'] ?? null) ? OrgDate::toOrgCalendarDate($p['end_date']) : null,
            'settled' => $settled,
        ];
    }

    /** Σ pnl of the open (not settled) positions. */
    public static function unrealized(array $positions): float
    {
        return round(array_sum(array_map(fn ($p) => $p['settled'] ? 0 : ($p['pnl'] ?? 0), $positions)), 2);
    }

    /** Positions table rows get `ends` here (needs today). */
    private function withEnds(array $rows): array
    {
        return array_map(function (array $p) {
            $end = $p['endDate'];
            $p['endLabel'] = $end ? $this->dateLabel($end) : null;
            $p['ends'] = null;
            if ($p['settled']) {
                $p['ends'] = ['kind' => 'settled', 'days' => null];
            } elseif ($end !== null) {
                $days = OrgDate::calendarDaysBetween($this->today, $end);
                $p['ends'] = ['kind' => match (true) {
                    $days < 0 => 'awaiting',
                    $days === 0 => 'today',
                    $days === 1 => 'tomorrow',
                    default => 'days',
                }, 'days' => $days];
            }

            return $p;
        }, $rows);
    }

    /** @return list<array<string, mixed>> */
    private function points(): array
    {
        return PolymarketSeriesPoint::query()->orderBy('ts')->get()->map(function (PolymarketSeriesPoint $r) {
            $t = $r->instant()->timezone(OrgDate::TIMEZONE);

            return [
                'ts' => $t->toIso8601String(),
                'at' => $t,
                'day' => $t->format('Y-m-d'),
                'min' => (int) $t->format('G') * 60 + (int) $t->format('i'),
                'time' => $t->format('H:i'),
                'label' => $t->format('D j M, H:i'),
                'book' => (float) $r->book_value_usd,
                'cash' => $r->cash_usd === null ? null : (float) $r->cash_usd,
                'stop' => $r->stop_usd === null ? null : (float) $r->stop_usd,
            ];
        })->all();
    }

    /** Highest book value among the runs and the live mark (earliest on ties). */
    private static function peak(array $points, CarbonImmutable $asOf, float $book): ?array
    {
        $best = null;
        foreach ($points as $p) {
            if ($best === null || $p['book'] > $best['book']) {
                $best = $p;
            }
        }
        if ($best === null || $book > $best['book']) {
            return ['book' => $book, 'label' => $asOf->format('D j M, H:i'), 'dayLabel' => $asOf->format('D j M')];
        }

        return ['book' => $best['book'], 'label' => $best['label'], 'dayLabel' => $best['at']->format('D j M')];
    }

    /** Book value of the last run before $t (or at $t when inclusive). */
    private static function lastBefore(array $points, CarbonImmutable $t, bool $inclusive = false): ?float
    {
        $found = null;
        foreach ($points as $p) {
            if ($p['at'] < $t || ($inclusive && $p['at'] == $t)) {
                $found = $p['book'];
            }
        }

        return $found;
    }

    private static function delta(float $book, ?float $then): ?float
    {
        return $then === null ? null : round($book - $then, 2);
    }

    /** @return array<string, mixed> */
    private function chart(array $points, CarbonImmutable $asOf, float $book, ?float $cash, ?float $hw, ?float $funded, ?array $peak, array $track): array
    {
        $firstDay = $points[0]['day'] ?? $asOf->format('Y-m-d');
        $lastDay = max($this->today, $asOf->format('Y-m-d'));
        $days = [];
        for ($d = $firstDay, $i = 0; $d <= $lastDay && $i < 1000; $d = OrgDate::addCalendarDays($d, 1), $i++) {
            $c = CarbonImmutable::createFromFormat('!Y-m-d', $d, 'UTC');
            $days[] = [
                'date' => $d,
                'd' => $c->format('j'),
                'wd' => substr($c->format('D'), 0, 2),
                'mon' => $c->format('M'),
                'weekend' => (int) $c->format('N') >= 6,
                'today' => $d === $this->today,
            ];
        }

        $prev = null;
        $out = [];
        foreach ($points as $p) {
            $out[] = [
                'ts' => $p['ts'],
                'day' => $p['day'],
                'min' => $p['min'],
                'label' => $p['label'],
                'book' => $p['book'],
                'cash' => $p['cash'],
                'stop' => $p['stop'],
                'prevTime' => $prev === null ? null : ($prev['day'] === $p['day'] ? $prev['time'] : substr($prev['label'], 0, 3).' '.$prev['time']),
            ];
            $prev = $p;
        }
        $last = $points === [] ? null : $points[count($points) - 1];
        $stops = array_values(array_filter($points, fn ($p) => $p['stop'] !== null));
        $lastStop = $stops === [] ? null : $stops[count($stops) - 1];

        return [
            'days' => $days,
            'points' => $out,
            'live' => [
                'ts' => $asOf->toIso8601String(),
                'day' => $asOf->format('Y-m-d'),
                'min' => (int) $asOf->format('G') * 60 + (int) $asOf->format('i'),
                'label' => $asOf->format('D j M, H:i'),
                'time' => $asOf->format('H:i'),
                'book' => $book,
                'cash' => $cash,
                // Drawn as its own point only when it is later than the last run.
                'separate' => $last === null || $asOf > $last['at'],
                'lastRunTime' => $last['time'] ?? null,
            ],
            'funded' => $funded,
            'highWater' => $hw,
            'peak' => $peak,
            'stop' => $lastStop === null ? null : [
                'usd' => $lastStop['stop'],
                'fromLabel' => $stops[0]['at']->format('j M'),
                'toLabel' => $lastStop['at']->format('j M'),
                'removed' => $lastStop !== $last,
            ],
            'sweeps' => $track,
            'defaultRange' => count($days) < 30 ? 'all' : '30',
        ];
    }

    /**
     * Redeems & sweeps from sweeps_detail (or sweeps / redeems_today in older shapes), newest first.
     *
     * @return array{available: bool, items: list<array<string, mixed>>, redeemed: float, swept: float, sweepCount: int, lastSweepLabel: ?string, recent: bool, track: list<array<string, mixed>>}
     */
    private function sweeps(array $s): array
    {
        $raw = null;
        foreach (['sweeps_detail', 'sweeps', 'redeems_today'] as $k) {
            if (is_array($s[$k] ?? null)) {
                $raw = $s[$k];
                break;
            }
        }
        $items = [];
        foreach (array_filter($raw ?? [], 'is_array') as $w) {
            if (! is_string($w['ts'] ?? null) || self::num($w['amount_usd'] ?? null) === null) {
                continue;
            }
            $t = CarbonImmutable::parse($w['ts'])->timezone(OrgDate::TIMEZONE);
            $type = is_string($w['type'] ?? null) ? strtolower($w['type']) : 'sweep';
            $precision = is_string($w['ts_precision'] ?? null) ? $w['ts_precision'] : null;
            $time = $t->format('H:i');
            $approx = false;
            if ($precision !== null && $precision !== 'exact') {
                $approx = true;
                $time = preg_match('/between\s+(\d{1,2}:\d{2})\s+and\s+(\d{1,2}:\d{2})/i', $precision, $m) === 1 ? "~{$m[1]}–{$m[2]}" : "~$time";
            }
            $items[] = [
                'at' => $t,
                'type' => $type === 'redeem' ? 'redeem' : 'sweep',
                'ts' => $t->toIso8601String(),
                'day' => $t->format('Y-m-d'),
                'dayLabel' => $t->format('D j M'),
                'min' => (int) $t->format('G') * 60 + (int) $t->format('i'),
                'time' => $time,
                'approx' => $approx,
                'amount' => self::num($w['amount_usd']),
                'market' => is_string($w['market'] ?? null) ? $w['market'] : null,
            ];
        }
        // Newest first; the same instant keeps the feed's order (usort is stable).
        usort($items, fn ($a, $b) => $b['at'] <=> $a['at']);

        $sweeps = array_values(array_filter($items, fn ($i) => $i['type'] === 'sweep'));
        $track = [];
        foreach (array_reverse($sweeps) as $w) {
            $track[$w['day']] ??= ['day' => $w['day'], 'min' => $w['min'], 'total' => 0.0, 'count' => 0];
            $track[$w['day']]['total'] = round($track[$w['day']]['total'] + $w['amount'], 2);
            $track[$w['day']]['count']++;
        }
        $weekAgo = $this->now->subDays(7);

        return [
            'available' => $raw !== null,
            'items' => array_map(fn ($i) => array_diff_key($i, ['at' => 1, 'min' => 1]), $items),
            'redeemed' => round(self::sum(array_filter($items, fn ($i) => $i['type'] === 'redeem'), 'amount'), 2),
            'swept' => round(self::sum($sweeps, 'amount'), 2),
            'sweepCount' => count($sweeps),
            'lastSweepLabel' => $sweeps === [] ? null : $sweeps[0]['dayLabel'],
            'recent' => array_filter($items, fn ($i) => $i['at'] >= $weekAgo) !== [],
            'track' => array_values($track),
        ];
    }

    /** @return array<string, mixed> */
    private function fills(array $s): array
    {
        $raw = is_array($s['fills'] ?? null) ? $s['fills'] : (is_array($s['fills_today'] ?? null) ? $s['fills_today'] : null);
        $all = [];
        foreach (array_filter($raw ?? [], 'is_array') as $f) {
            if (! is_string($f['ts'] ?? null)) {
                continue;
            }
            $t = CarbonImmutable::parse($f['ts'])->timezone(OrgDate::TIMEZONE);
            $side = is_string($f['side'] ?? null) ? ucfirst(strtolower($f['side'])) : null;
            $all[] = [
                'at' => $t,
                'ts' => $t->toIso8601String(),
                'day' => $t->format('Y-m-d'),
                'dayLabel' => $t->format('D j M'),
                'time' => $t->format('H:i'),
                'side' => $side,
                'outcome' => is_string($f['outcome'] ?? null) ? $f['outcome'] : null,
                'market' => is_string($f['market'] ?? null) ? $f['market'] : null,
                'shares' => self::num($f['shares'] ?? null),
                'price' => self::num($f['price'] ?? null),
                'usdc' => self::num($f['usdc'] ?? null),
            ];
        }
        usort($all, fn ($a, $b) => $b['at'] <=> $a['at']);
        $weekAgo = $this->now->subDays(7);
        $recent = array_values(array_filter($all, fn ($f) => $f['at'] >= $weekAgo));
        $today = array_filter($recent, fn ($f) => $f['day'] === $this->today);

        return [
            'available' => $raw !== null,
            'items' => array_map(fn ($f) => array_diff_key($f, ['at' => 1]), $recent),
            'bought' => round(self::sum(array_filter($recent, fn ($f) => $f['side'] === 'Buy'), 'usdc'), 2),
            'sold' => round(self::sum(array_filter($recent, fn ($f) => $f['side'] === 'Sell'), 'usdc'), 2),
            'noneToday' => $recent !== [] && $today === [],
            'lastFillLabel' => $all === [] ? null : $this->relative($all[0]['at']),
        ];
    }

    /**
     * fresh | late | stale (DATA-SPEC §5), with the badge and banner copy.
     *
     * A post is expected every weekday at POST_HOUR (local wall clock). Weekday D's post is missed when
     * now ≥ D GRACE_UNTIL:00 and the snapshot is from before D WINDOW_OPENS:00. No missed posts → fresh
     * (a Friday post stays fresh all weekend), one → late, two or more → stale.
     *
     * @return array{state: string, badge: string, banner: array{title: string, body: string, key: string}|null}
     */
    private function freshness(CarbonImmutable $asOf): array
    {
        $missed = 0;
        $latestMissed = null;
        for ($i = 0; $i <= self::LOOKBACK_DAYS; $i++) {
            $day = $this->now->startOfDay()->subDays($i);
            if ($day->isWeekend() || $day->setTime(self::GRACE_UNTIL, 0) > $this->now || $day->setTime(self::WINDOW_OPENS, 0) <= $asOf) {
                continue;
            }
            $missed++;
            $latestMissed ??= $day;
        }

        if ($latestMissed === null) {
            return ['state' => 'fresh', 'badge' => 'Fresh', 'banner' => null];
        }
        $key = 'Last post '.$asOf->format('D H:i');
        if ($missed === 1) {
            $dow = $latestMissed->format('D');

            return ['state' => 'late', 'badge' => "Late · missed $dow post", 'banner' => [
                'title' => "The $dow ~".sprintf('%02d:00', self::POST_HOUR).' post hasn’t arrived.',
                'body' => 'Figures are from '.$asOf->format('D j M, H:i').' and may have moved.',
                'key' => $key,
            ]];
        }

        return ['state' => 'stale', 'badge' => "Stale · $missed posts missed", 'banner' => [
            'title' => "$missed weekday posts missed.",
            'body' => 'Book value and prices are out of date. Check Captain’s weekday desk routine.',
            'key' => $key,
        ]];
    }

    /** @return array{status: ?string, country: ?string, geoblock: ?bool}|null */
    private static function gateway(mixed $g): ?array
    {
        if (! is_array($g)) {
            return null;
        }

        return [
            'status' => is_string($g['status'] ?? null) ? $g['status'] : null,
            'country' => is_string($g['country'] ?? null) ? $g['country'] : null,
            'geoblock' => is_bool($g['geoblock'] ?? null) ? $g['geoblock'] : null,
        ];
    }

    /**
     * Header pill: the latest run's stop, else "none since {day the last stop was seen}".
     *
     * @return array{usd: ?float, sinceLabel: ?string}
     */
    private static function stop(array $points, ?float $level): array
    {
        $last = $points === [] ? null : $points[count($points) - 1];
        if ($last !== null && $last['stop'] !== null) {
            return ['usd' => $last['stop'], 'sinceLabel' => null];
        }
        if ($last === null && $level !== null) {
            return ['usd' => $level, 'sinceLabel' => null];
        }
        $since = null;
        foreach ($points as $p) {
            if ($p['stop'] !== null) {
                $since = $p['at']->format('j M');
            }
        }

        return ['usd' => null, 'sinceLabel' => $since];
    }

    /** @return array{cost: ?float, value: ?float, pnl: ?float, pnlPct: ?float, count: int} */
    private static function totals(array $rows): array
    {
        $cost = self::sum($rows, 'cost');
        $pnl = self::sum($rows, 'pnl');

        return [
            'cost' => $rows === [] ? null : round($cost, 2),
            'value' => $rows === [] ? null : round(self::sum($rows, 'value'), 2),
            'pnl' => $rows === [] ? null : round($pnl, 2),
            'pnlPct' => $cost > 0 ? $pnl / $cost * 100 : null,
            'count' => count($rows),
        ];
    }

    private static function sum(iterable $rows, string $key): float
    {
        $t = 0.0;
        foreach ($rows as $r) {
            $t += $r[$key] ?? 0;
        }

        return $t;
    }

    /** Polymarket event link: the feed's url when it is on polymarket.com, else built from event_slug. */
    private static function marketUrl(array $p): ?string
    {
        $url = $p['url'] ?? null;
        if (is_string($url) && preg_match('#^https://polymarket\.com/[A-Za-z0-9._~/%-]*$#', $url) === 1) {
            return $url;
        }
        $slug = $p['event_slug'] ?? null;
        if (is_string($slug) && preg_match('/^[A-Za-z0-9._-]{1,300}$/', $slug) === 1) {
            return 'https://polymarket.com/event/'.$slug;
        }

        return null;
    }

    /** "Today, 21:52" · "Yesterday, 18:13" · "Wed 14 Oct, 16:14" (year added when not this year). */
    private function relative(CarbonImmutable $t): string
    {
        $t = $t->timezone(OrgDate::TIMEZONE);
        $diff = OrgDate::calendarDaysBetween($t->format('Y-m-d'), $this->today);
        if ($diff === 0) {
            return 'Today, '.$t->format('H:i');
        }
        if ($diff === 1) {
            return 'Yesterday, '.$t->format('H:i');
        }

        return $t->format($t->year === $this->now->year ? 'D j M, H:i' : 'D j M Y, H:i');
    }

    /** "25 Oct" (year added when not this year). */
    private function dateLabel(string $date): string
    {
        $c = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');

        return $c->format($c->year === $this->now->year ? 'j M' : 'j M Y');
    }

    /** ISO instant → "3 Oct" in Ljubljana; null stays null. */
    private static function dayLabel(mixed $iso): ?string
    {
        return is_string($iso) ? CarbonImmutable::parse($iso)->timezone(OrgDate::TIMEZONE)->format('j M') : null;
    }

    private static function num(mixed $v): ?float
    {
        return is_int($v) || is_float($v) ? (float) $v : null;
    }
}
