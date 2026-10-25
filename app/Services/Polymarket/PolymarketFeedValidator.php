<?php

namespace App\Services\Polymarket;

use App\Services\Usage\UsageDayValidator;
use JsonException;
use stdClass;

/**
 * Schema check for POST /api/polymarket/feed: `{ "snapshot": {feed.json}, "series": [book_series points] }`.
 *
 * Strict where Bridge computes (types of every known key, ISO 8601 instants with an offset, finite numbers),
 * lenient where Frank's schema evolves: unknown keys are allowed anywhere inside the snapshot and the points
 * and are stored as-is. Both position key styles are accepted (outcome|side, avg_price|avg_entry,
 * value_usd|current_value_usd), so the older latest.json shape validates too.
 *
 * Decodes with objects (not assoc arrays) so `{}` and `[]` stay distinguishable.
 */
final class PolymarketFeedValidator
{
    public const MAX_BYTES = 1048576; // 1 MB, HTTP body

    /** Per file for `bridge:polymarket-import` (a long series is larger than one request). */
    public const MAX_FILE_BYTES = 8388608; // 8 MB

    public const MAX_POINTS = 20000;

    /** decimal(12,2) holds |x| < 1e10. */
    public const CAP = 1e10;

    private const MAX_ERRORS = 50;

    private const MAX_ITEMS = 5000;

    private const SNAPSHOT_NUMBERS = ['cash_usd', 'open_value_usd', 'high_water_usd', 'funded_usd', 'total_pnl_usd', 'realized_pnl_usd', 'sweeps_total_usd', 'stop_level_usd'];

    private const SNAPSHOT_INSTANTS = ['funded_since', 'realized_since', 'note_ts'];

    private const SNAPSHOT_STRINGS = ['note' => 20000, 'last_decision' => 200, 'total_pnl_method' => 2000, 'realized_method' => 2000, 'realized_note' => 4000, 'funded_note' => 4000, 'cost_basis' => 2000, 'stop_note' => 2000];

    private const SNAPSHOT_LISTS = ['fills', 'fills_today', 'sweeps', 'sweeps_detail', 'redeems_today', 'history'];

    private const POSITION_NUMBERS = ['shares', 'avg_price', 'avg_entry', 'cost_usd', 'cur_price', 'value_usd', 'current_value_usd', 'pnl_usd', 'pnl_pct'];

    private const POSITION_STRINGS = ['slug', 'event_slug', 'url', 'outcome', 'side'];

    private const FILL_NUMBERS = ['shares', 'price', 'usdc'];

    private const FILL_STRINGS = ['market', 'slug', 'outcome', 'side'];

    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @return array{snapshot: array<string, mixed>, series: list<array{as_of: string, book_value_usd: int|float, cash_usd: int|float|null, source: string|null}>}
     *
     * @throws InvalidPolymarketFeed
     */
    public function validateBody(string $raw): array
    {
        if (strlen($raw) > self::MAX_BYTES) {
            throw new InvalidPolymarketFeed(['body' => ['The body is larger than 1 MB.']]);
        }
        $doc = $this->decode($raw, 'body');
        if (! $doc instanceof stdClass) {
            throw new InvalidPolymarketFeed(['body' => ['The body must be a JSON object.']]);
        }

        $this->errors = [];
        foreach (['snapshot', 'series'] as $k) {
            if (! property_exists($doc, $k)) {
                $this->fail($k, 'Required.');
            }
        }
        foreach (array_diff(array_keys(get_object_vars($doc)), ['snapshot', 'series']) as $k) {
            $this->fail(mb_substr((string) $k, 0, 64), 'Unknown key.');
        }
        if ($this->errors !== []) {
            throw new InvalidPolymarketFeed($this->errors);
        }

        return $this->check($doc->snapshot, $doc->series);
    }

    /**
     * Two files (feed.json, book_series.json), for the artisan import.
     *
     * @return array{snapshot: array<string, mixed>, series: list<array{as_of: string, book_value_usd: int|float, cash_usd: int|float|null, source: string|null}>}
     *
     * @throws InvalidPolymarketFeed
     */
    public function validateFiles(string $snapshotRaw, string $seriesRaw): array
    {
        return $this->check($this->decode($snapshotRaw, 'snapshot'), $this->decode($seriesRaw, 'series'));
    }

    private function decode(string $raw, string $path): mixed
    {
        try {
            return json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidPolymarketFeed([$path => ['Not valid JSON.']]);
        }
    }

    /** @return array{snapshot: array<string, mixed>, series: list<array<string, mixed>>} */
    private function check(mixed $snapshot, mixed $series): array
    {
        $this->errors = [];
        $this->checkSnapshot($snapshot);
        $points = $this->checkSeries($series);

        if ($this->errors !== []) {
            throw new InvalidPolymarketFeed($this->errors);
        }

        return ['snapshot' => self::toArray($snapshot), 'series' => $points];
    }

    private function checkSnapshot(mixed $s): void
    {
        if (! $s instanceof stdClass) {
            $this->fail('snapshot', 'Must be an object.');

            return;
        }
        $p = 'snapshot.';

        if (! property_exists($s, 'as_of')) {
            $this->fail($p.'as_of', 'Required.');
        } else {
            $this->instant($s->as_of, $p.'as_of');
        }
        if (! property_exists($s, 'book_value_usd')) {
            $this->fail($p.'book_value_usd', 'Required.');
        } else {
            $this->number($s->book_value_usd, $p.'book_value_usd');
        }

        foreach (self::SNAPSHOT_NUMBERS as $k) {
            if (property_exists($s, $k)) {
                $this->number($s->$k, $p.$k, nullable: true);
            }
        }
        if (property_exists($s, 'open_count') && $s->open_count !== null && (! is_int($s->open_count) || $s->open_count < 0 || $s->open_count > 100000)) {
            $this->fail($p.'open_count', 'Must be null or a non-negative integer.');
        }
        foreach (self::SNAPSHOT_INSTANTS as $k) {
            if (property_exists($s, $k)) {
                $this->instant($s->$k, $p.$k, nullable: true);
            }
        }
        foreach (self::SNAPSHOT_STRINGS as $k => $max) {
            if (property_exists($s, $k)) {
                $this->string($s->$k, $p.$k, $max, nullable: true);
            }
        }
        if (property_exists($s, 'gateway') && $s->gateway !== null) {
            $this->checkGateway($s->gateway, $p.'gateway');
        }

        if (! property_exists($s, 'positions')) {
            $this->fail($p.'positions', 'Required.');
        } elseif ($this->list($s->positions, $p.'positions', 1000)) {
            foreach ($s->positions as $i => $pos) {
                $this->checkPosition($pos, $p."positions.$i");
            }
        }

        foreach (self::SNAPSHOT_LISTS as $k) {
            if (! property_exists($s, $k) || $s->$k === null) {
                continue;
            }
            $max = $k === 'history' ? self::MAX_POINTS : self::MAX_ITEMS;
            if (! $this->list($s->$k, $p.$k, $max)) {
                continue;
            }
            foreach ($s->$k as $i => $item) {
                match ($k) {
                    'fills', 'fills_today' => $this->checkFill($item, $p."$k.$i"),
                    'sweeps', 'sweeps_detail', 'redeems_today' => $this->checkSweep($item, $p."$k.$i"),
                    'history' => $this->checkHistory($item, $p."$k.$i"),
                };
            }
        }
    }

    private function checkGateway(mixed $g, string $path): void
    {
        if (! $g instanceof stdClass) {
            $this->fail($path, 'Must be null or an object.');

            return;
        }
        foreach (['status', 'country'] as $k) {
            if (property_exists($g, $k)) {
                $this->string($g->$k, "$path.$k", 64, nullable: true);
            }
        }
        if (property_exists($g, 'geoblock')) {
            $this->bool($g->geoblock, "$path.geoblock");
        }
    }

    private function checkPosition(mixed $pos, string $path): void
    {
        if (! $pos instanceof stdClass) {
            $this->fail($path, 'Must be an object.');

            return;
        }
        if (! property_exists($pos, 'market')) {
            $this->fail("$path.market", 'Required.');
        } else {
            $this->string($pos->market, "$path.market", 500);
        }
        foreach (self::POSITION_NUMBERS as $k) {
            if (property_exists($pos, $k)) {
                $this->number($pos->$k, "$path.$k", nullable: true);
            }
        }
        foreach (self::POSITION_STRINGS as $k) {
            if (property_exists($pos, $k)) {
                $this->string($pos->$k, "$path.$k", 1000, nullable: true);
            }
        }
        foreach (['redeemable', 'settled'] as $k) {
            if (property_exists($pos, $k)) {
                $this->bool($pos->$k, "$path.$k");
            }
        }
        if (property_exists($pos, 'end_date') && $pos->end_date !== null
            && (! is_string($pos->end_date) || ! (UsageDayValidator::isRealDate($pos->end_date) || UsageDayValidator::isIsoWithOffset($pos->end_date)))) {
            $this->fail("$path.end_date", 'Must be null, a date (YYYY-MM-DD) or an ISO 8601 timestamp with a UTC offset.');
        }
    }

    private function checkFill(mixed $f, string $path): void
    {
        if (! $f instanceof stdClass) {
            $this->fail($path, 'Must be an object.');

            return;
        }
        if (! property_exists($f, 'ts')) {
            $this->fail("$path.ts", 'Required.');
        } else {
            $this->instant($f->ts, "$path.ts");
        }
        foreach (self::FILL_NUMBERS as $k) {
            if (property_exists($f, $k)) {
                $this->number($f->$k, "$path.$k", nullable: true);
            }
        }
        foreach (self::FILL_STRINGS as $k) {
            if (property_exists($f, $k)) {
                $this->string($f->$k, "$path.$k", 1000, nullable: true);
            }
        }
    }

    private function checkSweep(mixed $w, string $path): void
    {
        if (! $w instanceof stdClass) {
            $this->fail($path, 'Must be an object.');

            return;
        }
        if (! property_exists($w, 'ts')) {
            $this->fail("$path.ts", 'Required.');
        } else {
            $this->instant($w->ts, "$path.ts");
        }
        if (! property_exists($w, 'amount_usd')) {
            $this->fail("$path.amount_usd", 'Required.');
        } else {
            $this->number($w->amount_usd, "$path.amount_usd");
        }
        foreach (['type' => 32, 'market' => 1000, 'ts_precision' => 120, 'tx' => 200] as $k => $max) {
            if (property_exists($w, $k)) {
                $this->string($w->$k, "$path.$k", $max, nullable: true);
            }
        }
    }

    private function checkHistory(mixed $h, string $path): void
    {
        if (! $h instanceof stdClass) {
            $this->fail($path, 'Must be an object.');

            return;
        }
        foreach (['ts', 'book_value_usd'] as $k) {
            if (! property_exists($h, $k)) {
                $this->fail("$path.$k", 'Required.');
            }
        }
        if (property_exists($h, 'ts')) {
            $this->instant($h->ts, "$path.ts");
        }
        if (property_exists($h, 'book_value_usd')) {
            $this->number($h->book_value_usd, "$path.book_value_usd");
        }
        if (property_exists($h, 'stop_usd')) {
            $this->number($h->stop_usd, "$path.stop_usd", nullable: true);
        }
    }

    /** @return list<array{as_of: string, book_value_usd: int|float, cash_usd: int|float|null, source: string|null}> */
    private function checkSeries(mixed $series): array
    {
        if (! $this->list($series, 'series', self::MAX_POINTS)) {
            return [];
        }
        $points = [];
        foreach ($series as $i => $pt) {
            $path = "series.$i";
            if (! $pt instanceof stdClass) {
                $this->fail($path, 'Must be an object.');

                continue;
            }
            $before = count($this->errors);
            foreach (['as_of', 'book_value_usd'] as $k) {
                if (! property_exists($pt, $k)) {
                    $this->fail("$path.$k", 'Required.');
                }
            }
            if (property_exists($pt, 'as_of')) {
                $this->instant($pt->as_of, "$path.as_of");
            }
            if (property_exists($pt, 'book_value_usd')) {
                $this->number($pt->book_value_usd, "$path.book_value_usd");
            }
            if (property_exists($pt, 'cash_usd')) {
                $this->number($pt->cash_usd, "$path.cash_usd", nullable: true);
            }
            if (property_exists($pt, 'source')) {
                $this->string($pt->source, "$path.source", 255, nullable: true);
            }
            if (count($this->errors) === $before) {
                $points[] = [
                    'as_of' => $pt->as_of,
                    'book_value_usd' => $pt->book_value_usd,
                    'cash_usd' => $pt->cash_usd ?? null,
                    'source' => $pt->source ?? null,
                ];
            }
        }

        return $points;
    }

    private function list(mixed $v, string $path, int $max): bool
    {
        if (! is_array($v) || ! array_is_list($v)) {
            $this->fail($path, 'Must be an array.');

            return false;
        }
        if (count($v) > $max) {
            $this->fail($path, "At most $max items.");

            return false;
        }

        return true;
    }

    private function number(mixed $v, string $path, bool $nullable = false): void
    {
        if ($nullable && $v === null) {
            return;
        }
        if (! (is_int($v) || is_float($v)) || ! is_finite((float) $v) || abs((float) $v) >= self::CAP) {
            $this->fail($path, $nullable ? 'Must be null or a number below 1e10.' : 'Must be a number below 1e10.');
        }
    }

    private function instant(mixed $v, string $path, bool $nullable = false): void
    {
        if ($nullable && $v === null) {
            return;
        }
        if (! is_string($v) || ! UsageDayValidator::isIsoWithOffset($v)) {
            $this->fail($path, 'Must be an ISO 8601 timestamp with a UTC offset.');
        }
    }

    private function string(mixed $v, string $path, int $max, bool $nullable = false): void
    {
        if ($nullable && $v === null) {
            return;
        }
        if (! is_string($v) || mb_strlen($v) > $max) {
            $this->fail($path, ($nullable ? 'Must be null or a string' : 'Must be a string')." of at most $max characters.");
        }
    }

    private function bool(mixed $v, string $path): void
    {
        if ($v !== null && ! is_bool($v)) {
            $this->fail($path, 'Must be null, true or false.');
        }
    }

    private function fail(string $path, string $message): void
    {
        if (count($this->errors) < self::MAX_ERRORS) {
            $this->errors[$path][] = $message;
        }
    }

    /** stdClass tree → associative arrays (objects keep string keys, `{}` becomes []). */
    private static function toArray(mixed $v): mixed
    {
        if ($v instanceof stdClass) {
            $v = get_object_vars($v);
        }
        if (is_array($v)) {
            return array_map(self::toArray(...), $v);
        }

        return $v;
    }
}
