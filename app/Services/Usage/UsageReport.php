<?php

namespace App\Services\Usage;

use App\Models\UsageDay;
use App\Support\OrgDate;
use Carbon\CarbonImmutable;

/**
 * Everything the Usage pages show, built from usage_days. The UI does no estimating:
 * every figure is a sum or ratio of the files' est_* fields (counts: turns, runs).
 *
 * - Range = last N days ending today (Europe/Ljubljana), N = 7 | 30, or "yesterday": the one
 *   calendar day before today's Ljubljana date (from = to = that day). A day without a row is an
 *   explicit `present: false` entry, never a zero.
 * - Chart series: every agent with a nonzero est_tokens_total summed over the range, ranked by
 *   that sum (desc, then name). Agents at zero for the range are left out (chart, legend, and the
 *   All agents table for its own scope). One segment per series per day; they sum to the day total
 *   because the validator requires est_tokens_total == sum(agents[].est_tokens_total).
 * - Colours: #RRGGBB per agent_id from AgentColors, placed over every stored day, so an agent has
 *   the same colour in every range, list and on its detail page.
 * - "Top agents" / "Top routines" lists are always TOP_LIST (5) long, independent of the chart.
 * - "All agents" table is always the last 7 days (optionally one ?day); in the yesterday range it
 *   is yesterday unless ?day says otherwise. Its sparkline column is always the last 7 days.
 * - Factor: daily files carry no calibration flag (spec data gap 5), so K != params.median_K
 *   means "calibrated", otherwise "median K".
 */
final class UsageReport
{
    public const YESTERDAY = 'yesterday';

    public const RANGES = [self::YESTERDAY, 7, 30];

    /** Length of the side "Top agents" / "Top routines" lists. */
    public const TOP_LIST = 5;

    private const UNMATCHED = 'unmatched';

    private CarbonImmutable $now;

    private string $today;

    /** @var array<string, array<string, mixed>> date => payload, only readable rows inside the loaded window */
    private array $files = [];

    /** @var list<string> dates whose stored payload could not be read */
    private array $unreadable = [];

    /** @var array<string, string>|null agent_id => #RRGGBB over all stored days, built once */
    private ?array $colors = null;

    public function __construct(?CarbonImmutable $now = null)
    {
        $this->now = ($now ?? CarbonImmutable::now(OrgDate::TIMEZONE))->timezone(OrgDate::TIMEZONE);
        $this->today = $this->now->format('Y-m-d');
    }

    /** 7 | 30 | 'yesterday'; anything else is 7. */
    public static function normalizeRange(mixed $range): int|string
    {
        if (! is_scalar($range)) {
            return 7;
        }
        $range = (string) $range;

        return match ($range) {
            '7', '30' => (int) $range,
            self::YESTERDAY => self::YESTERDAY,
            default => 7,
        };
    }

    /** @return array<string, mixed> props for Usage/Overview */
    public function overview(int|string $range, ?string $day = null): array
    {
        $range = self::normalizeRange($range);
        $dates = $this->dates($range);
        $to = end($dates);
        // The All agents sparklines need the last 7 days, which hold yesterday too.
        $this->load($range === self::YESTERDAY ? $this->dates(7) : $dates);

        $agents = $this->aggregate($dates);
        $rangeTotal = $this->sumTotal($dates);
        $charted = self::withUsage($agents);
        $present = array_values(array_filter($dates, fn ($d) => isset($this->files[$d])));

        $top = array_slice($agents, 0, self::TOP_LIST);
        $chartedIds = array_column($charted, 'id');

        $series = array_map(fn ($a) => [
            'agentId' => $a['id'],
            'name' => $a['name'],
            'color' => $this->colorOf($a['id']),
            'total' => $a['tokens'],
        ], $charted);

        $days = array_map(function (string $d) use ($chartedIds) {
            $file = $this->files[$d] ?? null;
            $entry = $this->dayEntry($d) + ['total' => null, 'segments' => null];
            if ($file !== null) {
                $byId = array_column($file['agents'], 'est_tokens_total', 'agent_id');
                $entry['total'] = (int) $file['est_tokens_total'];
                $entry['segments'] = array_map(fn ($id) => (int) ($byId[$id] ?? 0), $chartedIds);
            }

            return $entry;
        }, $dates);

        [$routines, $unmatched] = $this->routines($dates);

        return [
            'range' => $range,
            'today' => $this->today,
            'from' => $dates[0],
            'to' => $to,
            'fromLabel' => self::longLabel($dates[0]),
            'toLabel' => self::longLabel($to),
            'empty' => $present === [],
            'daysWithFile' => count($present),
            'rangeTotal' => $rangeTotal,
            'agentCount' => count($charted),
            'updatedAt' => $this->updatedAt($to),
            'generatedAt' => $this->files[$to]['generated_at'] ?? null,
            'coverageNotes' => $this->coverageNotes($dates),
            'unreadable' => $this->unreadableFiles($dates),
            'series' => $series,
            'days' => $days,
            'topAgents' => array_map(fn ($a) => [
                'agentId' => $a['id'],
                'name' => $a['name'],
                'color' => $this->colorOf($a['id']),
                'tokens' => $a['tokens'],
                'share' => $rangeTotal > 0 ? $a['tokens'] / $rangeTotal : 0,
                'turns' => $a['turns'],
            ], $top),
            'topRoutines' => array_map(fn ($r) => $r + ['color' => $this->colorOf($r['agentId'])], array_slice($routines, 0, self::TOP_LIST)),
            'unmatched' => $unmatched,
            'table' => $this->table($day, $range === self::YESTERDAY ? $to : null),
            'method' => $this->latest($dates)['method'] ?? null,
        ];
    }

    /** @return array<string, mixed>|null props for Usage/Agent, null when the agent never appears in any stored day */
    public function agent(string $agentId, int|string $range): ?array
    {
        $identity = $this->findAgent($agentId);
        if ($identity === null) {
            return null;
        }

        $range = self::normalizeRange($range);
        $dates = $this->dates($range);
        $to = end($dates);
        $this->load($dates);

        $agents = $this->aggregate($dates);
        $rangeTotal = $this->sumTotal($dates);
        $rank = array_search($agentId, array_column($agents, 'id'), true);
        $mine = $rank === false ? null : $agents[$rank];

        $days = array_map(function (string $d) use ($agentId) {
            $a = $this->agentIn($d, $agentId);

            return $this->dayEntry($d) + ['value' => isset($this->files[$d]) ? (int) ($a['est_tokens_total'] ?? 0) : null];
        }, $dates);

        // Hour view: the last 7 days of the range (also in the 30-day range, stated in the sub-line); yesterday: that day.
        $hourDates = array_slice($dates, -7);
        $hourTotals = array_fill(0, 24, 0);
        $heat = [];
        foreach ($hourDates as $d) {
            $present = isset($this->files[$d]);
            $a = $this->agentIn($d, $agentId);
            $hours = $present ? array_map('intval', $a['by_hour'] ?? array_fill(0, 24, 0)) : null;
            foreach ($hours ?? [] as $h => $v) {
                $hourTotals[$h] += $v;
            }
            $cutoff = null;
            if ($present && $d === $this->today) {
                $cutoff = (int) CarbonImmutable::parse($this->files[$d]['generated_at'])->timezone(OrgDate::TIMEZONE)->format('G');
            }
            $heat[] = $this->dayEntry($d) + ['hours' => $hours, 'cutoffHour' => $cutoff];
        }

        // Routine table for this agent over the range.
        $rows = [];
        $unmatched = ['runs' => 0, 'tokens' => 0];
        $other = 0;
        foreach ($dates as $d) {
            $a = $this->agentIn($d, $agentId);
            if ($a === null) {
                continue;
            }
            $other += (int) $a['other_est_tokens'];
            foreach ($a['by_routine'] as $r) {
                if ($r['routine_id'] === self::UNMATCHED) {
                    $unmatched['runs'] += (int) $r['runs'];
                    $unmatched['tokens'] += (int) $r['est_tokens'];

                    continue;
                }
                $row = &$rows[$r['routine_id']];
                $row ??= ['routineId' => $r['routine_id'], 'name' => $r['name'], 'runs' => 0, 'tokens' => 0];
                $row['runs'] += (int) $r['runs'];
                $row['tokens'] += (int) $r['est_tokens'];
                unset($row);
            }
        }
        $rows = array_values($rows);
        usort($rows, fn ($x, $y) => $y['tokens'] <=> $x['tokens'] ?: strcmp($x['name'], $y['name']));
        $tokens = $mine['tokens'] ?? 0;
        $share = fn (int $v) => $tokens > 0 ? $v / $tokens : 0;
        $routineRows = array_map(fn ($r) => $r + ['kind' => 'routine', 'share' => $share($r['tokens']), 'perRun' => $r['runs'] > 0 ? intdiv($r['tokens'], $r['runs']) : null], $rows);
        if ($unmatched['runs'] > 0 || $unmatched['tokens'] > 0) {
            $routineRows[] = ['kind' => 'unmatched', 'routineId' => self::UNMATCHED, 'name' => 'Unmatched wakes', 'runs' => $unmatched['runs'], 'tokens' => $unmatched['tokens'], 'share' => $share($unmatched['tokens']), 'perRun' => $unmatched['runs'] > 0 ? intdiv($unmatched['tokens'], $unmatched['runs']) : null];
        }
        $routineRows[] = ['kind' => 'other', 'routineId' => 'other_est_tokens', 'name' => 'Other: chats, tasks and subagents', 'runs' => null, 'tokens' => $other, 'share' => $share($other), 'perRun' => null];
        $matchedRuns = array_sum(array_column($rows, 'runs'));

        // Name and factor come from the agent's latest file in range (else its latest stored file).
        [$latest, $file] = $this->agentLatest($dates, $agentId) ?? [$identity, $identity['_file']];

        return [
            'range' => $range,
            'today' => $this->today,
            'from' => $dates[0],
            'to' => $to,
            'fromLabel' => self::longLabel($dates[0]),
            'toLabel' => self::longLabel($to),
            'hoursFromLabel' => self::longLabel($hourDates[0]),
            'updatedAt' => $this->updatedAt($to),
            'agent' => [
                'id' => $agentId,
                'name' => $latest['name'],
                'color' => $this->colorOf($agentId),
                'rank' => $rank === false ? null : $rank + 1,
                'of' => count($agents),
                'factor' => self::factor($latest, $file),
                'K' => $latest['K'],
                'medianK' => $file['params']['median_K'],
            ],
            'zero' => $tokens === 0,
            'stats' => [
                'tokens' => $tokens,
                'share' => $rangeTotal > 0 ? $tokens / $rangeTotal : 0,
                'io' => $mine['io'] ?? 0,
                'turns' => $mine['turns'] ?? 0,
                'matchedRuns' => $matchedRuns,
                'unmatchedRuns' => $unmatched['runs'],
            ],
            'days' => $days,
            'hourTotals' => $hourTotals,
            'heat' => $heat,
            'routines' => $routineRows,
            'routineTotals' => ['wakes' => $matchedRuns + $unmatched['runs'], 'tokens' => $tokens],
            'unreadable' => $this->unreadableFiles($dates),
        ];
    }

    // ---- internals -------------------------------------------------------------------

    /** @return list<string> Y-m-d, oldest first, ending today; yesterday: [today - 1 calendar day] */
    private function dates(int|string $n): array
    {
        if ($n === self::YESTERDAY) {
            return [$this->yesterday()];
        }
        $out = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $out[] = OrgDate::addCalendarDays($this->today, -$i);
        }

        return $out;
    }

    /** @param list<string> $dates */
    private function load(array $dates): void
    {
        $this->files = [];
        $this->unreadable = [];
        $rows = UsageDay::query()->whereBetween('date', [$dates[0], end($dates)])->get(['date', 'payload']);
        foreach ($rows as $row) {
            $date = substr((string) $row->getRawOriginal('date'), 0, 10);
            $p = $row->payload;
            if (! is_array($p) || ! isset($p['agents'], $p['est_tokens_total']) || ! is_array($p['agents'])) {
                $this->unreadable[] = $date;

                continue;
            }
            $this->files[$date] = $p;
        }
    }

    /**
     * Agents in the given days, ranked by summed est_tokens_total (desc, then name).
     *
     * @return list<array{id: string, name: string, tokens: int, io: int, turns: int}>
     */
    private function aggregate(array $dates): array
    {
        $acc = [];
        foreach ($dates as $d) {
            foreach ($this->files[$d]['agents'] ?? [] as $a) {
                $row = &$acc[$a['agent_id']];
                $row ??= ['id' => $a['agent_id'], 'name' => $a['name'], 'tokens' => 0, 'io' => 0, 'turns' => 0];
                $row['name'] = $a['name']; // latest name wins (dates are ascending)
                $row['tokens'] += (int) $a['est_tokens_total'];
                $row['io'] += (int) $a['est_io_tokens'];
                $row['turns'] += (int) $a['turns'];
                unset($row);
            }
        }
        $acc = array_values($acc);
        usort($acc, fn ($x, $y) => $y['tokens'] <=> $x['tokens'] ?: strcmp($x['name'], $y['name']));

        return $acc;
    }

    /**
     * The chart series: ranked agents with a nonzero total, in the same order.
     *
     * @template T of array{tokens: int}
     *
     * @param  list<T>  $ranked
     * @return list<T>
     */
    public static function withUsage(array $ranked): array
    {
        return array_values(array_filter($ranked, fn ($a) => $a['tokens'] > 0));
    }

    private function colorOf(string $agentId): string
    {
        return $this->colors()[$agentId] ?? AgentColors::fallback($agentId);
    }

    /**
     * Colours over every stored day (not just the range), agents placed in order of first
     * appearance, then agent_id; see AgentColors. Reads agent ids from the raw JSON so the
     * payloads are not decoded.
     *
     * @return array<string, string>
     */
    private function colors(): array
    {
        if ($this->colors !== null) {
            return $this->colors;
        }
        $order = [];
        foreach (UsageDay::query()->orderBy('date')->select(['date', 'payload'])->cursor() as $row) {
            preg_match_all('/"agent_id":"((?:[^"\\\\]|\\\\.)*)"/', (string) $row->getRawOriginal('payload'), $m);
            $ids = array_values(array_diff(array_unique(array_map(fn ($id) => json_decode('"'.$id.'"') ?? $id, $m[1])), array_keys($order)));
            sort($ids, SORT_STRING);
            foreach ($ids as $id) {
                $order[$id] = true;
            }
        }

        return $this->colors = AgentColors::assign(array_map('strval', array_keys($order)));
    }

    private function sumTotal(array $dates): int
    {
        $sum = 0;
        foreach ($dates as $d) {
            $sum += (int) ($this->files[$d]['est_tokens_total'] ?? 0);
        }

        return $sum;
    }

    /** @return array{0: list<array<string, mixed>>, 1: array{runs: int, agents: int, tokens: int}} */
    private function routines(array $dates): array
    {
        $acc = [];
        $um = ['runs' => 0, 'agents' => [], 'tokens' => 0];
        foreach ($dates as $d) {
            foreach ($this->files[$d]['agents'] ?? [] as $a) {
                foreach ($a['by_routine'] as $r) {
                    if ($r['routine_id'] === self::UNMATCHED) {
                        $um['runs'] += (int) $r['runs'];
                        $um['tokens'] += (int) $r['est_tokens'];
                        if ((int) $r['runs'] > 0) {
                            $um['agents'][$a['agent_id']] = true;
                        }

                        continue;
                    }
                    $key = $a['agent_id']."\0".$r['routine_id'];
                    $row = &$acc[$key];
                    $row ??= ['routineId' => $r['routine_id'], 'name' => $r['name'], 'agentId' => $a['agent_id'], 'agentName' => $a['name'], 'runs' => 0, 'tokens' => 0];
                    $row['name'] = $r['name'];
                    $row['agentName'] = $a['name'];
                    $row['runs'] += (int) $r['runs'];
                    $row['tokens'] += (int) $r['est_tokens'];
                    unset($row);
                }
            }
        }
        $acc = array_values($acc);
        usort($acc, fn ($x, $y) => $y['tokens'] <=> $x['tokens'] ?: strcmp($x['name'], $y['name']));
        $acc = array_map(fn ($r) => $r + ['perRun' => $r['runs'] > 0 ? intdiv($r['tokens'], $r['runs']) : null], $acc);

        return [$acc, ['runs' => $um['runs'], 'agents' => count($um['agents']), 'tokens' => $um['tokens']]];
    }

    /** Yesterday in Europe/Ljubljana: calendar arithmetic on today's org date, never on a UTC instant. */
    private function yesterday(): string
    {
        return OrgDate::addCalendarDays($this->today, -1);
    }

    /** All agents table: last 7 days, or one day when ?day= is given, else $rangeDay (the yesterday range). */
    private function table(?string $day, ?string $rangeDay = null): array
    {
        $week = $this->dates(7); // files for the last 7 days are always loaded (overview loads them in every range)
        $filter = $day !== null && UsageDayValidator::isRealDate($day) ? $day : $rangeDay;

        if ($filter !== null && ! isset($this->files[$filter]) && ! in_array($filter, $week, true)) {
            // A direct link to a day outside the loaded range: look it up on its own.
            $row = UsageDay::query()->where('date', $filter)->first(['date', 'payload']);
            if ($row !== null && is_array($row->payload) && isset($row->payload['agents'])) {
                $this->files[$filter] = $row->payload;
            }
        }

        $scope = $filter !== null ? [$filter] : $week;
        $ranked = self::withUsage($this->aggregate($scope));
        $total = $this->sumTotal($scope);
        $routineRuns = [];
        $unmatched = [];
        $factor = [];
        foreach ($scope as $d) {
            $file = $this->files[$d] ?? null;
            foreach ($file['agents'] ?? [] as $a) {
                $id = $a['agent_id'];
                $factor[$id] = self::factor($a, $file);
                foreach ($a['by_routine'] as $r) {
                    $bucket = $r['routine_id'] === self::UNMATCHED ? 'u' : 'r';
                    if ($bucket === 'u') {
                        $unmatched[$id] = ($unmatched[$id] ?? 0) + (int) $r['runs'];
                    } else {
                        $routineRuns[$id] = ($routineRuns[$id] ?? 0) + (int) $r['runs'];
                    }
                }
            }
        }

        $rows = array_map(fn ($a) => [
            'agentId' => $a['id'],
            'name' => $a['name'],
            'color' => $this->colorOf($a['id']),
            'tokens' => $a['tokens'],
            'share' => $total > 0 ? $a['tokens'] / $total : 0,
            'daily' => array_map(fn ($d) => isset($this->files[$d]) ? (int) ($this->agentIn($d, $a['id'])['est_tokens_total'] ?? 0) : null, $week),
            'turns' => $a['turns'],
            'routineRuns' => $routineRuns[$a['id']] ?? 0,
            'unmatched' => $unmatched[$a['id']] ?? 0,
            'io' => $a['io'],
            'factor' => $factor[$a['id']] ?? 'median',
        ], $ranked);

        return [
            'day' => $filter,
            'dayLabel' => $filter !== null ? self::longLabel($filter) : null,
            'dayMissing' => $filter !== null && ! isset($this->files[$filter]),
            'from' => $week[0],
            'fromLabel' => self::longLabel($week[0]),
            'toLabel' => self::longLabel(end($week)),
            'dailyLabels' => array_map(fn ($d) => self::shortLabel($d), $week),
            'rows' => $rows,
            'totals' => [
                'tokens' => $total,
                'turns' => array_sum(array_column($rows, 'turns')),
                'routineRuns' => array_sum(array_column($rows, 'routineRuns')),
                'unmatched' => array_sum(array_column($rows, 'unmatched')),
                'io' => array_sum(array_column($rows, 'io')),
            ],
        ];
    }

    private function agentIn(string $date, string $agentId): ?array
    {
        foreach ($this->files[$date]['agents'] ?? [] as $a) {
            if ($a['agent_id'] === $agentId) {
                return $a;
            }
        }

        return null;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>}|null [agent, its file] */
    private function agentLatest(array $dates, string $agentId): ?array
    {
        foreach (array_reverse($dates) as $d) {
            if ($a = $this->agentIn($d, $agentId)) {
                return [$a, $this->files[$d]];
            }
        }

        return null;
    }

    private function latest(array $dates): ?array
    {
        foreach (array_reverse($dates) as $d) {
            if (isset($this->files[$d])) {
                return $this->files[$d];
            }
        }

        return null;
    }

    /** Latest stored appearance of the agent anywhere (for 404 and out-of-range names). */
    private function findAgent(string $agentId): ?array
    {
        $needle = '"agent_id":"'.$agentId.'"';
        $rows = UsageDay::query()
            ->where('payload', 'like', '%'.$needle.'%')
            ->orderByDesc('date')
            ->get(['date', 'payload']);
        foreach ($rows as $row) {
            foreach ($row->payload['agents'] ?? [] as $a) {
                if (($a['agent_id'] ?? null) === $agentId) {
                    return $a + ['_file' => $row->payload];
                }
            }
        }

        return null;
    }

    private static function factor(array $agent, ?array $file): string
    {
        $median = $file['params']['median_K'] ?? null;

        return $median !== null && (float) $agent['K'] !== (float) $median ? 'calibrated' : 'median';
    }

    /** generated_at (HH:MM, Ljubljana) of the range's last day: today, or yesterday in that range. */
    private function updatedAt(string $date): ?string
    {
        $g = $this->files[$date]['generated_at'] ?? null;

        return $g ? CarbonImmutable::parse($g)->timezone(OrgDate::TIMEZONE)->format('H:i') : null;
    }

    private function coverageNotes(array $dates): array
    {
        $out = [];
        foreach ($dates as $d) {
            $note = $this->files[$d]['coverage_note'] ?? null;
            if (is_string($note) && $note !== '') {
                $out[] = ['date' => $d, 'label' => self::longLabel($d), 'note' => $note];
            }
        }

        return $out;
    }

    private function unreadableFiles(array $dates): array
    {
        return array_values(array_map(fn ($d) => "$d.json", array_intersect($this->unreadable, $dates)));
    }

    private function dayEntry(string $d): array
    {
        return [
            'date' => $d,
            'label' => self::shortLabel($d),
            'longLabel' => self::longLabel($d),
            'present' => isset($this->files[$d]),
            'today' => $d === $this->today,
        ];
    }

    /** "Tue 13" */
    public static function shortLabel(string $d): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $d, 'UTC')->format('D j');
    }

    /** "Tue 13 Oct" */
    public static function longLabel(string $d): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $d, 'UTC')->format('D j M');
    }
}
