<?php

namespace App\Services\Usage;

use App\Models\CliUsageDay;
use App\Models\CliUsageImport;
use App\Support\OrgDate;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports the CSV from cursor.com/dashboard → Usage → Export CSV (individual plan: one account per file, so every row is
 * attributed to the single collector agent "Cursor (all agents)"; there is no per-agent split). Headers are matched by
 * name (case-insensitive, trimmed, any order, BOM tolerated); extra columns (Kind, Total Tokens, Cost to you, Cloud
 * Agent ID, Automation ID, User, ...) are ignored. Required: REQUIRED. Mapping: input = "Input (w/o Cache Write)",
 * cache_creation = "Input (w/ Cache Write)" (the cache-write count on its own), cache_read = "Cache Read", output =
 * "Output Tokens", calls = rows, max_mode_calls = rows with Max Mode yes/true (null without the column), cost_cents =
 * Σ "Cost" in dollars × 100 ("-", "Free", "Included", "" = 0; null for every row when no row of the file has a numeric
 * cost). Rows of any Kind count, also "Errored, No Charge" / "Aborted, Not Charged": Cursor processed those tokens.
 *
 * Date (UTC ISO) → Europe/Ljubljana day (OrgDate). Rows are aggregated per day × model into collector rows and every day
 * from the first to the last day of the file goes through CliUsageImporter with covered_tools ["cursor"] (Claude Code /
 * Kimi / OpenCode rows are never touched), coverage_from = first day; days without rows get a zero marker. The first and
 * last day are likely partial (the export window cuts them): such an edge day replaces a stored Cursor day only when
 * that day has no Cursor marker yet or the new calls >= the stored calls; otherwise it is kept. All days are written in
 * one transaction; any malformed row → InvalidUsageDay (line numbers, at most MAX_ERRORS) and nothing is written.
 */
final class CursorCsvImporter
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_ROWS = 200_000;

    public const MAX_ERRORS = 20;

    public const TOOL = 'cursor';

    public const AGENT_ID = 'cursor';

    public const AGENT_NAME = 'Cursor (all agents)';

    /** Collector key => CSV header. */
    public const REQUIRED = [
        'date' => 'Date',
        'model' => 'Model',
        'input' => 'Input (w/o Cache Write)',
        'cache_creation' => 'Input (w/ Cache Write)',
        'cache_read' => 'Cache Read',
        'output' => 'Output Tokens',
    ];

    public const OPTIONAL = ['cost' => 'Cost', 'max_mode' => 'Max Mode'];

    public const STATE_CREATED = 'created';

    public const STATE_REPLACED = 'replaced';

    public const STATE_KEPT = 'kept (partial edge day)';

    /** Cost cells that mean "nothing charged" (lower case). */
    private const ZERO_COSTS = ['', '-', 'free', 'included'];

    /** @var array<string, list<string>> */
    private array $errors = [];

    public function __construct(
        private readonly CliUsageImporter $importer,
        private readonly CliUsageValidator $validator,
        private readonly CurrentWorkspace $current,
    ) {}

    /**
     * Parse, aggregate and import a whole export into the current workspace (console: the default workspace).
     *
     * @return array{dry_run: bool, from: string, to: string, rows: int, total_tokens: int, days_imported: int,
     *     days_zero: int, days_kept: int, days: list<array{date: string, state: string, calls: int, total: int,
     *     stored_calls: int|null}>}
     *
     * @throws InvalidUsageDay
     */
    public function import(string $csv, bool $dryRun = false): array
    {
        [$byDay, $rowCount, $hasCost, $hasMaxMode] = $this->parse($csv);
        $dates = array_keys($byDay);
        sort($dates);
        $from = $dates[0];
        $to = end($dates);

        $files = [];
        for ($d = $from; strcmp($d, $to) <= 0; $d = OrgDate::addCalendarDays($d, 1)) {
            $files[$d] = $this->collectorFile($d, $from, $byDay[$d] ?? [], $hasCost, $hasMaxMode);
        }

        $workspaceId = $this->current->idForWrite(CliUsageDay::class);
        $run = function () use ($files, $from, $to, $workspaceId, $dryRun) {
            $days = [];
            foreach ($files as $date => $cli) {
                $calls = array_sum(array_column($cli['tools'], 'calls'));
                $total = array_sum(array_map(fn ($r) => $r['input'] + $r['output'] + $r['cache_read'] + $r['cache_creation'], $cli['tools']));
                $stored = $this->storedCalls($workspaceId, $date);
                $edge = $date === $from || $date === $to;
                if ($edge && $stored !== null && $calls < $stored) {
                    $state = self::STATE_KEPT;
                } else {
                    $state = $stored === null ? self::STATE_CREATED : self::STATE_REPLACED;
                    if (! $dryRun) {
                        $this->importer->import($cli);
                    }
                }
                $days[] = ['date' => $date, 'state' => $state, 'calls' => $calls, 'total' => $total, 'stored_calls' => $stored];
            }

            return $days;
        };
        $days = $dryRun ? $run() : DB::transaction($run);

        $written = array_filter($days, fn ($d) => $d['state'] !== self::STATE_KEPT);

        return [
            'dry_run' => $dryRun,
            'from' => $from,
            'to' => $to,
            'rows' => $rowCount,
            'total_tokens' => array_sum(array_column($days, 'total')),
            'days_imported' => count(array_filter($written, fn ($d) => $d['calls'] > 0)),
            'days_zero' => count(array_filter($written, fn ($d) => $d['calls'] === 0)),
            'days_kept' => count($days) - count($written),
            'days' => $days,
        ];
    }

    /** The calls stored for Cursor on this day, null when no Cursor import covered it (no marker). */
    private function storedCalls(?int $workspaceId, string $date): ?int
    {
        $marker = CliUsageImport::acrossWorkspaces()
            ->where('workspace_id', $workspaceId)->where('date', $date)->where('tool', self::TOOL)->exists();

        return $marker ? (int) CliUsageDay::acrossWorkspaces()
            ->where('workspace_id', $workspaceId)->where('date', $date)->where('tool', self::TOOL)->sum('calls') : null;
    }

    /**
     * One day as a collector object, checked by CliUsageValidator like any other file.
     *
     * @param  array<string, array<string, int>>  $models  model => sums
     * @return array<string, mixed>
     *
     * @throws InvalidUsageDay
     */
    private function collectorFile(string $date, string $from, array $models, bool $hasCost, bool $hasMaxMode): array
    {
        ksort($models, SORT_STRING);
        $rows = [];
        foreach ($models as $model => $s) {
            $row = [
                'tool' => self::TOOL,
                'agent_id' => self::AGENT_ID,
                'name' => self::AGENT_NAME,
                'model' => $model === '' ? null : (string) $model,
                'input' => $s['input'],
                'output' => $s['output'],
                'cache_read' => $s['cache_read'],
                'cache_creation' => $s['cache_creation'],
                'calls' => $s['calls'],
            ];
            if ($hasCost) {
                // Micro-dollars → cents with 4 decimals (cli_usage_days.cost_cents is decimal(12,4)).
                $row['cost_cents'] = $s['cost_micro'] / 10_000;
            }
            if ($hasMaxMode) {
                $row['max_mode_calls'] = $s['max_mode'];
            }
            $rows[] = $row;
        }
        $cli = [
            'date' => $date,
            'timezone' => OrgDate::TIMEZONE,
            'generated_at' => CarbonImmutable::now(OrgDate::TIMEZONE)->toIso8601String(),
            'source' => 'measured',
            'coverage_from' => $from,
            'tools' => $rows,
            'method' => 'Cursor dashboard CSV export (cursor.com/dashboard → Usage → Export CSV), one account, '
                .'aggregated per Europe/Ljubljana day × model by CursorCsvImporter.',
            'covered_tools' => [self::TOOL],
        ];

        $doc = json_decode(json_encode($cli, JSON_THROW_ON_ERROR), false, 8, JSON_THROW_ON_ERROR);
        $errors = $this->validator->check($doc, "$date.");
        if ($errors !== []) {
            throw new InvalidUsageDay($errors);
        }

        return $cli;
    }

    /**
     * @return array{0: array<string, array<string, array<string, int>>>, 1: int, 2: bool, 3: bool}
     *                                                                                              [day => model => sums, data rows, any numeric cost, Max Mode column present]
     *
     * @throws InvalidUsageDay
     */
    private function parse(string $csv): array
    {
        $this->errors = [];
        if (strlen($csv) > self::MAX_BYTES) {
            throw new InvalidUsageDay(['file' => ['The file is larger than 10 MB.']]);
        }
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }
        if (! mb_check_encoding($csv, 'UTF-8')) {
            throw new InvalidUsageDay(['file' => ['The file is not UTF-8 text.']]);
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        try {
            return $this->parseStream($stream);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function parseStream($stream): array
    {
        $header = fgetcsv($stream, null, ',', '"', '');
        if ($header === false || $header === [null]) {
            throw new InvalidUsageDay(['file' => ['The file is empty.']]);
        }
        $columns = [];
        foreach ($header as $i => $name) {
            $key = self::normalizeHeader((string) $name);
            $columns[$key] ??= $i;
        }
        $index = [];
        $missing = [];
        foreach (self::REQUIRED + self::OPTIONAL as $key => $name) {
            $i = $columns[self::normalizeHeader($name)] ?? null;
            if ($i === null && isset(self::REQUIRED[$key])) {
                $missing[] = $name;
            }
            $index[$key] = $i;
        }
        if ($missing !== []) {
            throw new InvalidUsageDay(['header' => [
                'Missing required column(s): '.implode(', ', $missing).'. Found: '.self::describeHeaders($header).'.',
            ]]);
        }

        $byDay = [];
        $rows = 0;
        $hasCost = false;
        $line = 1;
        while (($cells = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $line++;
            if ($cells === [null] || (count($cells) === 1 && trim((string) $cells[0]) === '')) {
                continue; // blank line
            }
            if (++$rows > self::MAX_ROWS) {
                throw new InvalidUsageDay(['file' => ['More than '.number_format(self::MAX_ROWS).' rows.']]);
            }
            $row = $this->row($cells, $index, $line);
            if ($row === null) {
                continue;
            }
            $hasCost = $hasCost || $row['cost_micro'] !== null;
            $s = &$byDay[$row['day']][$row['model']];
            $s ??= ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0, 'calls' => 0, 'cost_micro' => 0, 'max_mode' => 0];
            foreach (['input', 'output', 'cache_read', 'cache_creation'] as $k) {
                $s[$k] += $row[$k];
            }
            $s['calls']++;
            $s['cost_micro'] += $row['cost_micro'] ?? 0;
            $s['max_mode'] += $row['max_mode'] ? 1 : 0;
            unset($s);
        }

        if ($this->errors !== []) {
            throw new InvalidUsageDay($this->errors);
        }
        if ($rows === 0) {
            throw new InvalidUsageDay(['file' => ['The file has no data rows.']]);
        }
        foreach ($byDay as $day => $models) {
            foreach ($models as $sums) {
                foreach (['input', 'output', 'cache_read', 'cache_creation'] as $k) {
                    if ($sums[$k] > CliUsageValidator::CAP) {
                        throw new InvalidUsageDay(['file' => ["Token sums on $day exceed 1e15."]]);
                    }
                }
            }
        }

        return [$byDay, $rows, $hasCost, $index['max_mode'] !== null];
    }

    /**
     * One data row; null (and an error recorded) when malformed. Error messages name the column, never the value.
     *
     * @param  list<string|null>  $cells
     * @param  array<string, int|null>  $index
     * @return array{day: string, model: string, input: int, output: int, cache_read: int, cache_creation: int,
     *     cost_micro: int|null, max_mode: bool}|null
     */
    private function row(array $cells, array $index, int $line): ?array
    {
        $cell = fn (string $key) => $index[$key] === null ? null : trim((string) ($cells[$index[$key]] ?? ''));
        $bad = [];

        $day = self::day((string) $cell('date'));
        if ($day === null) {
            $bad[] = 'Date (expected an ISO 8601 UTC timestamp)';
        }
        $model = (string) $cell('model');
        if (mb_strlen($model) > CliUsageValidator::MODEL_MAX || preg_match('/\p{Cc}/u', $model) === 1) {
            $bad[] = 'Model (at most '.CliUsageValidator::MODEL_MAX.' characters, no control characters)';
        }
        $tokens = [];
        foreach (['input', 'output', 'cache_read', 'cache_creation'] as $k) {
            $tokens[$k] = self::tokens((string) $cell($k));
            if ($tokens[$k] === null) {
                $bad[] = self::REQUIRED[$k].' (expected a whole number)';
            }
        }
        $cost = null;
        if ($index['cost'] !== null) {
            $cost = self::costMicro((string) $cell('cost'));
            if ($cost === false) {
                $bad[] = 'Cost (expected dollars, e.g. $0.11)';
            }
        }

        if ($bad !== []) {
            if (count($this->errors) < self::MAX_ERRORS) {
                $this->errors["line $line"] = $bad;
            }

            return null;
        }

        return [
            'day' => $day,
            'model' => $model,
            ...$tokens,
            'cost_micro' => $cost,
            'max_mode' => in_array(strtolower((string) $cell('max_mode')), ['yes', 'true'], true),
        ];
    }

    private static function normalizeHeader(string $name): string
    {
        return strtolower((string) preg_replace('/\s+/u', ' ', trim($name, " \t\n\r\0\x0B\u{FEFF}\"")));
    }

    /** Header names for the 422 message: trimmed, control characters dropped, each ≤ 64 characters, at most 30. */
    private static function describeHeaders(array $header): string
    {
        $names = array_map(fn ($n) => mb_substr((string) preg_replace('/\p{Cc}/u', '', trim((string) $n)), 0, 64), array_slice($header, 0, 30));
        $names = array_values(array_filter($names, fn ($n) => $n !== ''));

        return $names === [] ? '(none)' : implode(', ', $names).(count($header) > 30 ? ', …' : '');
    }

    /** UTC ISO timestamp (Z or an offset) → Europe/Ljubljana calendar day. */
    private static function day(string $v): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:?\d{2})$/i', $v) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::parse($v)->timezone(OrgDate::TIMEZONE)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    /** Token count: digits, optional thousands separators or a ".0" tail; "" and "-" = 0. Null when malformed. */
    private static function tokens(string $v): ?int
    {
        if ($v === '' || $v === '-') {
            return 0;
        }
        if (preg_match('/^(\d{1,3}(,\d{3})+|\d+)(\.0+)?$/', $v, $m) !== 1) {
            return null;
        }
        $digits = str_replace(',', '', $m[1]);
        if (strlen(ltrim($digits, '0')) > 16 || (int) $digits > CliUsageValidator::CAP) {
            return null;
        }

        return (int) $digits;
    }

    /** Cost in dollars → integer micro-dollars; null for "no charge" words, false when malformed. */
    private static function costMicro(string $v): int|false|null
    {
        if (in_array(strtolower($v), self::ZERO_COSTS, true)) {
            return null;
        }
        $v = trim(ltrim($v, '$'));
        if (preg_match('/^(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d+))?$/', $v, $m) !== 1) {
            return false;
        }
        $whole = str_replace(',', '', $m[1]);
        if (strlen(ltrim($whole, '0')) > 9) {
            return false; // ≥ $1e9 in one row
        }
        $frac = $m[2] ?? '';
        $micro = (int) $whole * 1_000_000 + (int) substr(str_pad($frac, 6, '0'), 0, 6);
        if (strlen($frac) > 6 && (int) $frac[6] >= 5) {
            $micro++;
        }

        return $micro;
    }
}
