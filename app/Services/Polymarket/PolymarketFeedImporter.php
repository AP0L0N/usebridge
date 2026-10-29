<?php

namespace App\Services\Polymarket;

use App\Models\PolymarketSeriesPoint;
use App\Models\PolymarketSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Validate + store one Polymarket feed post. Shared by POST /api/polymarket/feed and
 * `php artisan bridge:polymarket-import`.
 *
 * - Snapshot: one current row. Replaced when the incoming as_of >= the stored one ("replaced", or
 *   "unchanged" when as_of and payload hash are identical); an older snapshot is ignored ("kept_newer").
 *   Stored without `history` (the chart reads polymarket_series_points).
 * - Series: upsert by ts (UTC instant). `history[]` from the snapshot fills stop_usd on the matching ts
 *   and adds points the series lacks (cash/source unknown). A row is written only when a value changed,
 *   so re-posting the same payload touches nothing.
 * - Everything in one transaction. The body is never logged.
 */
final class PolymarketFeedImporter
{
    private const CHUNK = 500;

    public function __construct(private readonly PolymarketFeedValidator $validator) {}

    /**
     * @return array{snapshot: string, as_of: string, series: array{received: int, inserted: int, updated: int, unchanged: int, total: int}}
     *
     * @throws InvalidPolymarketFeed
     */
    public function importBody(string $raw, bool $dryRun = false): array
    {
        return $this->store($this->validator->validateBody($raw), $dryRun);
    }

    /**
     * @return array{snapshot: string, as_of: string, series: array{received: int, inserted: int, updated: int, unchanged: int, total: int}}
     *
     * @throws InvalidPolymarketFeed
     */
    public function importFiles(string $snapshotRaw, string $seriesRaw, bool $dryRun = false): array
    {
        return $this->store($this->validator->validateFiles($snapshotRaw, $seriesRaw), $dryRun);
    }

    /** @param array{snapshot: array<string, mixed>, series: list<array<string, mixed>>} $feed */
    private function store(array $feed, bool $dryRun): array
    {
        $snapshot = $feed['snapshot'];
        $history = is_array($snapshot['history'] ?? null) ? $snapshot['history'] : [];
        unset($snapshot['history']);

        $payload = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $sha = hash('sha256', $payload);
        $asOf = self::utc($snapshot['as_of']);
        $wanted = self::mergePoints($feed['series'], $history);

        $run = function () use ($snapshot, $sha, $asOf, $wanted, $dryRun) {
            $state = $this->snapshot($snapshot, $sha, $asOf, $dryRun);
            $counts = $this->points($wanted, $dryRun);

            return [$state, $counts];
        };
        [$state, $counts] = $dryRun ? $run() : DB::transaction($run);

        if (in_array($state, ['created', 'replaced'], true)) {
            self::checkPnl($snapshot);
        }

        return [
            'snapshot' => $state,
            'as_of' => $snapshot['as_of'],
            'series' => ['received' => count($feed['series'])] + $counts,
        ];
    }

    /** @return 'created'|'replaced'|'unchanged'|'kept_newer' */
    private function snapshot(array $snapshot, string $sha, string $asOf, bool $dryRun): string
    {
        $row = PolymarketSnapshot::query()->orderByDesc('as_of')->orderByDesc('id')->lockForUpdate()->first();
        if ($row === null) {
            if (! $dryRun) {
                PolymarketSnapshot::query()->create([
                    'as_of' => $asOf,
                    'as_of_raw' => $snapshot['as_of'],
                    'payload' => $snapshot,
                    'payload_sha256' => $sha,
                    'received_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
                ]);
            }

            return 'created';
        }
        $stored = substr((string) $row->as_of, 0, 19);
        if ($asOf < $stored) {
            return 'kept_newer';
        }
        // Same values count as unchanged even when the bytes differ (e.g. 0.0 vs 0 after a re-encode).
        if ($asOf === $stored && $row->as_of_raw === $snapshot['as_of'] && ($row->payload_sha256 === $sha || $row->payload == $snapshot)) {
            return 'unchanged';
        }
        if (! $dryRun) {
            $row->fill([
                'as_of' => $asOf,
                'as_of_raw' => $snapshot['as_of'],
                'payload' => $snapshot,
                'payload_sha256' => $sha,
                'received_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
            ])->save();
        }

        return 'replaced';
    }

    /**
     * Series points by UTC ts (last wins), then history: stop_usd onto the same ts, or a new point.
     *
     * @return array<string, array{ts_raw: string, book: ?string, cash: ?string, cash_set: bool, source: ?string, source_set: bool, stop: ?string, stop_set: bool, history_book: ?string}>
     */
    private static function mergePoints(array $series, array $history): array
    {
        $out = [];
        foreach ($series as $p) {
            $out[self::utc($p['as_of'])] = [
                'ts_raw' => $p['as_of'],
                'book' => self::money($p['book_value_usd']),
                'cash' => self::money($p['cash_usd']),
                'cash_set' => true,
                'source' => $p['source'],
                'source_set' => true,
                'stop' => null,
                'stop_set' => false,
                'history_book' => null,
            ];
        }
        foreach ($history as $h) {
            $ts = self::utc($h['ts']);
            $out[$ts] ??= [
                'ts_raw' => $h['ts'],
                'book' => null,
                'cash' => null,
                'cash_set' => false,
                'source' => null,
                'source_set' => false,
                'stop' => null,
                'stop_set' => false,
                'history_book' => null,
            ];
            $out[$ts]['history_book'] = self::money($h['book_value_usd']);
            if (array_key_exists('stop_usd', $h)) {
                $out[$ts]['stop'] = self::money($h['stop_usd']);
                $out[$ts]['stop_set'] = true;
            }
        }

        return $out;
    }

    /** @return array{inserted: int, updated: int, unchanged: int, total: int} */
    private function points(array $wanted, bool $dryRun): array
    {
        $inserted = $updated = $unchanged = 0;
        $now = CarbonImmutable::now();
        $inserts = [];

        foreach (array_chunk(array_keys($wanted), self::CHUNK) as $keys) {
            $existing = PolymarketSeriesPoint::query()->whereIn('ts', $keys)->get()->keyBy(fn ($r) => substr((string) $r->ts, 0, 19));
            foreach ($keys as $ts) {
                $w = $wanted[$ts];
                /** @var PolymarketSeriesPoint|null $row */
                $row = $existing->get($ts);
                if ($row === null) {
                    $inserted++;
                    $inserts[] = [
                        'ts' => $ts,
                        'ts_raw' => $w['ts_raw'],
                        'book_value_usd' => $w['book'] ?? $w['history_book'],
                        'cash_usd' => $w['cash'],
                        'stop_usd' => $w['stop'],
                        'source' => $w['source'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    continue;
                }
                $changes = [];
                if ($w['book'] !== null && self::money($row->book_value_usd) !== $w['book']) {
                    $changes['book_value_usd'] = $w['book'];
                }
                if ($w['cash_set'] && self::money($row->cash_usd) !== $w['cash']) {
                    $changes['cash_usd'] = $w['cash'];
                }
                if ($w['stop_set'] && self::money($row->stop_usd) !== $w['stop']) {
                    $changes['stop_usd'] = $w['stop'];
                }
                if ($w['source_set'] && $row->source !== $w['source']) {
                    $changes['source'] = $w['source'];
                }
                if ($changes === []) {
                    $unchanged++;

                    continue;
                }
                $updated++;
                if (! $dryRun) {
                    $row->fill($changes)->save();
                }
            }
        }

        if (! $dryRun) {
            foreach (array_chunk($inserts, self::CHUNK) as $chunk) {
                PolymarketSeriesPoint::query()->insert($chunk);
            }
        }

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'total' => PolymarketSeriesPoint::query()->count() + ($dryRun ? $inserted : 0),
        ];
    }

    /** Spec check: realized + unrealized (open positions) ≈ total P&L (±0.05). Logs numbers only. */
    private static function checkPnl(array $snapshot): void
    {
        $total = $snapshot['total_pnl_usd'] ?? null;
        $realized = $snapshot['realized_pnl_usd'] ?? null;
        if (! is_numeric($total) || ! is_numeric($realized)) {
            return;
        }
        $unrealized = DeskReport::unrealized(array_map(DeskReport::position(...), array_filter($snapshot['positions'] ?? [], 'is_array')));
        if (abs($realized + $unrealized - $total) > 0.05) {
            Log::warning('Polymarket feed: realized + unrealized P&L differs from total P&L.', [
                'as_of' => $snapshot['as_of'],
                'realized' => $realized,
                'unrealized' => round($unrealized, 2),
                'total' => $total,
            ]);
        }
    }

    /** ISO 8601 with offset → UTC 'Y-m-d H:i:s' (the stored, unique form). */
    public static function utc(string $iso): string
    {
        return CarbonImmutable::parse($iso)->utc()->format('Y-m-d H:i:s');
    }

    /** Money as a 2-decimal string, so DB values (string on MySQL, float on SQLite) compare exactly. */
    private static function money(mixed $v): ?string
    {
        return $v === null ? null : number_format((float) $v, 2, '.', '');
    }
}
