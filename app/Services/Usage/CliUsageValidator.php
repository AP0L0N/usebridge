<?php

namespace App\Services\Usage;

use App\Models\CliUsageDay;
use App\Support\OrgDate;
use JsonException;
use stdClass;

/**
 * Strict schema check for one collector file (bridge-usage/data/cli/YYYY-MM-DD.json, cli_usage.py), the
 * same object that an estimator day file carries under its optional `cli_usage` key. Same rules as
 * UsageDayValidator: exact keys at every level, real JSON integers capped at 1e15, 512 KB, tool in
 * CliUsageDay::TOOLS, every row with calls >= 1 (the collector writes no zero rows). Rows may carry an
 * optional `model` (null, or 1–128 characters without control characters); files from before the
 * collector reported models have none. One row per (tool, agent_id, model), where null and an absent
 * `model` are the same "not recorded" model. Decodes with objects so `{}` and `[]` stay distinguishable.
 *
 * Optional top-level `covered_tools`: distinct CliUsageDay::TOOLS (at most MAX_COVERED) this file is authoritative
 * for on its date, even with zero rows; when given, every row's tool must be in it (see CliUsageImporter). Optional
 * row keys `cost_cents` (number 0–1e12, may be fractional) and `max_mode_calls` (integer 0–calls), sent by Cursor.
 */
final class CliUsageValidator
{
    public const MAX_BYTES = UsageDayValidator::MAX_BYTES;

    public const CAP = UsageDayValidator::CAP;

    public const MAX_ROWS = 2000;

    private const MAX_ERRORS = 50;

    public const TOP_KEYS = ['date', 'timezone', 'generated_at', 'source', 'coverage_from', 'tools', 'method'];

    public const OPTIONAL_TOP_KEYS = ['covered_tools'];

    public const ROW_KEYS = ['tool', 'agent_id', 'name', 'input', 'output', 'cache_read', 'cache_creation', 'calls'];

    public const OPTIONAL_ROW_KEYS = ['model', 'cost_cents', 'max_mode_calls'];

    public const MAX_COVERED = 10;

    public const COST_CAP = 1_000_000_000_000; // 1e12 cents

    public const MODEL_MAX = 128;

    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @return array<string, mixed> the validated collector object as an associative array
     *
     * @throws InvalidUsageDay
     */
    public function validate(string $raw): array
    {
        if (strlen($raw) > self::MAX_BYTES) {
            throw new InvalidUsageDay(['body' => ['The file is larger than 512 KB.']]);
        }

        try {
            $doc = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidUsageDay(['body' => ['The body is not valid JSON.']]);
        }

        if (! $doc instanceof stdClass) {
            throw new InvalidUsageDay(['body' => ['The body must be a JSON object.']]);
        }

        $errors = $this->check($doc, '');
        if ($errors !== []) {
            throw new InvalidUsageDay($errors);
        }

        return json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    }

    /**
     * Checks an already decoded object; used by UsageDayValidator for the nested `cli_usage` key.
     *
     * @return array<string, list<string>> path => messages, [] when valid
     */
    public function check(stdClass $doc, string $prefix): array
    {
        $this->errors = [];
        $this->checkTop($doc, $prefix);

        return $this->errors;
    }

    private function checkTop(stdClass $doc, string $prefix): void
    {
        if (! $this->keys($doc, self::TOP_KEYS, $prefix, self::OPTIONAL_TOP_KEYS)) {
            return;
        }
        $covered = $this->coveredTools($doc, $prefix);

        if (! is_string($doc->date) || ! UsageDayValidator::isRealDate($doc->date)) {
            $this->fail($prefix.'date', 'Must be a real calendar date (YYYY-MM-DD).');
        }
        if ($doc->timezone !== OrgDate::TIMEZONE) {
            $this->fail($prefix.'timezone', 'Must be "'.OrgDate::TIMEZONE.'".');
        }
        if (! is_string($doc->generated_at) || ! UsageDayValidator::isIsoWithOffset($doc->generated_at)) {
            $this->fail($prefix.'generated_at', 'Must be an ISO 8601 timestamp with a UTC offset.');
        }
        if ($doc->source !== 'measured') {
            $this->fail($prefix.'source', 'Must be "measured".');
        }
        if (! is_string($doc->coverage_from) || ! UsageDayValidator::isRealDate($doc->coverage_from)) {
            $this->fail($prefix.'coverage_from', 'Must be a real calendar date (YYYY-MM-DD).');
        }
        if (! is_string($doc->method) || mb_strlen($doc->method) > 4000) {
            $this->fail($prefix.'method', 'Must be a string of at most 4000 characters.');
        }

        if (! is_array($doc->tools) || ! array_is_list($doc->tools)) {
            $this->fail($prefix.'tools', 'Must be an array.');

            return;
        }
        if (count($doc->tools) > self::MAX_ROWS) {
            $this->fail($prefix.'tools', 'At most '.self::MAX_ROWS.' rows.');

            return;
        }

        $seen = [];
        foreach ($doc->tools as $i => $row) {
            $path = $prefix."tools.$i";
            if (! $row instanceof stdClass) {
                $this->fail($path, 'Must be an object.');

                continue;
            }
            if (! $this->keys($row, self::ROW_KEYS, "$path.", self::OPTIONAL_ROW_KEYS)) {
                continue;
            }
            $model = $row->model ?? null;
            if ($model !== null && (! is_string($model) || $model === '' || mb_strlen($model) > self::MODEL_MAX
                || preg_match('/[\p{Cc}]/u', $model) !== 0)) {
                $this->fail("$path.model", 'Must be null or a string of 1–'.self::MODEL_MAX.' characters without control characters.');
            }
            if (! is_string($row->tool) || ! in_array($row->tool, CliUsageDay::TOOLS, true)) {
                $this->fail("$path.tool", 'Must be one of: '.implode(', ', CliUsageDay::TOOLS).'.');
            } elseif ($covered !== null && ! in_array($row->tool, $covered, true)) {
                $this->fail("$path.tool", 'Must be one of covered_tools.');
            }
            if (! is_string($row->agent_id) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $row->agent_id) !== 1) {
                $this->fail("$path.agent_id", 'Must be 1–64 characters of A–Z, a–z, 0–9, "-" or "_".');
            }
            if (! is_string($row->name) || $row->name === '' || mb_strlen($row->name) > 120) {
                $this->fail("$path.name", 'Must be a string of 1–120 characters.');
            }
            foreach (['input', 'output', 'cache_read', 'cache_creation'] as $k) {
                $this->int($row->$k, "$path.$k");
            }
            $this->int($row->calls, "$path.calls");
            if (is_int($row->calls) && $row->calls < 1) {
                $this->fail("$path.calls", 'Must be at least 1 (the collector writes no zero rows).');
            }
            if (property_exists($row, 'cost_cents')) {
                $cost = $row->cost_cents;
                if (! (is_int($cost) || is_float($cost)) || ! is_finite((float) $cost) || $cost < 0 || $cost > self::COST_CAP) {
                    $this->fail("$path.cost_cents", 'Must be a number between 0 and 1e12.');
                }
            }
            if (property_exists($row, 'max_mode_calls')) {
                $max = $row->max_mode_calls;
                if (! is_int($max) || $max < 0 || (is_int($row->calls) && $max > $row->calls)) {
                    $this->fail("$path.max_mode_calls", 'Must be an integer between 0 and calls.');
                }
            }
            if (is_string($row->tool) && is_string($row->agent_id) && ($model === null || is_string($model))) {
                $key = $row->tool."\0".$row->agent_id."\0".($model ?? '');
                if (isset($seen[$key])) {
                    $this->fail("$path.agent_id", 'Duplicate (tool, agent_id, model) in this day.');
                }
                $seen[$key] = true;
            }
        }
    }

    /** @return list<string>|null the valid covered_tools, null when absent or invalid (then rows are not checked against it) */
    private function coveredTools(stdClass $doc, string $prefix): ?array
    {
        if (! property_exists($doc, 'covered_tools')) {
            return null;
        }
        $covered = $doc->covered_tools;
        if (! is_array($covered) || ! array_is_list($covered) || count($covered) > self::MAX_COVERED) {
            $this->fail($prefix.'covered_tools', 'Must be an array of at most '.self::MAX_COVERED.' tools.');

            return null;
        }
        foreach ($covered as $tool) {
            if (! is_string($tool) || ! in_array($tool, CliUsageDay::TOOLS, true)) {
                $this->fail($prefix.'covered_tools', 'Each entry must be one of: '.implode(', ', CliUsageDay::TOOLS).'.');

                return null;
            }
        }
        if (count(array_unique($covered)) !== count($covered)) {
            $this->fail($prefix.'covered_tools', 'Must not repeat a tool.');

            return null;
        }

        return $covered;
    }

    /** Exact key set (plus optional keys): reports unknown and missing keys; false when the shape is unusable. */
    private function keys(stdClass $o, array $allowed, string $prefix, array $optional = []): bool
    {
        $present = array_keys(get_object_vars($o));
        $ok = true;
        foreach (array_diff($present, $allowed, $optional) as $k) {
            $this->fail($prefix.mb_substr((string) $k, 0, 64), 'Unknown key.');
            $ok = false;
        }
        foreach (array_diff($allowed, $present) as $k) {
            $this->fail($prefix.$k, 'Required.');
            $ok = false;
        }

        return $ok;
    }

    private function int(mixed $v, string $path): void
    {
        if (! is_int($v) || $v < 0 || $v > self::CAP) {
            $this->fail($path, 'Must be an integer between 0 and 1e15.');
        }
    }

    private function fail(string $path, string $message): void
    {
        if (count($this->errors) < self::MAX_ERRORS) {
            $this->errors[$path][] = $message;
        }
    }
}
