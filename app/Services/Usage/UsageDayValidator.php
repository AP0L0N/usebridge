<?php

namespace App\Services\Usage;

use App\Support\OrgDate;
use JsonException;
use stdClass;

/**
 * Strict schema check for one estimator day file (data/YYYY-MM-DD.json).
 * Exact keys at every level (unknown or missing key = invalid), real JSON integers for
 * int fields, values capped at 1e15, sum(agents[].est_tokens_total) == est_tokens_total.
 * by_hour is allowed to be off by rounding (spec "Sanity checks"), so it is not summed.
 *
 * Decodes with objects (not assoc arrays) so `{}` and `[]` stay distinguishable.
 */
final class UsageDayValidator
{
    public const MAX_BYTES = 524288; // 512 KB

    public const CAP = 1_000_000_000_000_000; // 1e15

    private const MAX_ERRORS = 50;

    private const TOP_KEYS = ['date', 'timezone', 'generated_at', 'est_tokens_total', 'agents', 'method', 'params', 'coverage_note'];

    private const PARAM_KEYS = ['wake_gap_ms', 'routine_window_min', 'wake_floor_tokens', 'chars_per_token', 'subagent_factor', 'median_K', 'ctx_cap_chars'];

    private const AGENT_KEYS = ['agent_id', 'name', 'est_tokens_total', 'est_io_tokens', 'turns', 'visible_chars', 'K', 'K_io', 'by_routine', 'other_est_tokens', 'by_hour'];

    private const ROUTINE_KEYS = ['routine_id', 'name', 'runs', 'est_tokens'];

    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @return array<string, mixed> the validated day as an associative array
     *
     * @throws InvalidUsageDay
     */
    public function validate(string $raw): array
    {
        $this->errors = [];

        if (strlen($raw) > self::MAX_BYTES) {
            throw new InvalidUsageDay(['body' => ['The file is larger than 512 KB.']]);
        }

        try {
            $doc = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidUsageDay(['body' => ['The body is not valid JSON.']]);
        }

        if (! $doc instanceof stdClass) {
            throw new InvalidUsageDay(['body' => ['The body must be a JSON object.']]);
        }

        $this->checkTop($doc);

        if ($this->errors !== []) {
            throw new InvalidUsageDay($this->errors);
        }

        return json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    }

    private function checkTop(stdClass $doc): void
    {
        if (! $this->keys($doc, self::TOP_KEYS, '')) {
            return;
        }

        if (! is_string($doc->date) || ! self::isRealDate($doc->date)) {
            $this->fail('date', 'Must be a real calendar date (YYYY-MM-DD).');
        }
        if ($doc->timezone !== OrgDate::TIMEZONE) {
            $this->fail('timezone', 'Must be "'.OrgDate::TIMEZONE.'".');
        }
        if (! is_string($doc->generated_at) || ! self::isIsoWithOffset($doc->generated_at)) {
            $this->fail('generated_at', 'Must be an ISO 8601 timestamp with a UTC offset.');
        }
        $this->int($doc->est_tokens_total, 'est_tokens_total');
        if (! is_string($doc->method) || mb_strlen($doc->method) > 4000) {
            $this->fail('method', 'Must be a string of at most 4000 characters.');
        }
        if ($doc->coverage_note !== null && (! is_string($doc->coverage_note) || mb_strlen($doc->coverage_note) > 1000)) {
            $this->fail('coverage_note', 'Must be null or a string of at most 1000 characters.');
        }

        $this->checkParams($doc->params);

        if (! is_array($doc->agents) || ! array_is_list($doc->agents)) {
            $this->fail('agents', 'Must be an array.');

            return;
        }
        if (count($doc->agents) > 200) {
            $this->fail('agents', 'At most 200 agents.');

            return;
        }

        $seen = [];
        $sum = 0;
        foreach ($doc->agents as $i => $agent) {
            $path = "agents.$i";
            if (! $this->checkAgent($agent, $path)) {
                continue;
            }
            if (is_string($agent->agent_id)) {
                if (isset($seen[$agent->agent_id])) {
                    $this->fail("$path.agent_id", 'Duplicate agent_id in this day.');
                }
                $seen[$agent->agent_id] = true;
            }
            if (is_int($agent->est_tokens_total)) {
                $sum += $agent->est_tokens_total;
            }
        }

        if ($this->errors === [] && $sum !== $doc->est_tokens_total) {
            $this->fail('est_tokens_total', 'Must equal the sum of agents[].est_tokens_total.');
        }
    }

    private function checkParams(mixed $p): void
    {
        if (! $p instanceof stdClass) {
            $this->fail('params', 'Must be an object.');

            return;
        }
        if (! $this->keys($p, self::PARAM_KEYS, 'params.')) {
            return;
        }
        foreach (['wake_gap_ms', 'wake_floor_tokens', 'chars_per_token', 'ctx_cap_chars'] as $k) {
            $this->int($p->$k, "params.$k");
        }
        foreach (['subagent_factor', 'median_K'] as $k) {
            $this->number($p->$k, "params.$k");
        }
        $w = $p->routine_window_min;
        if (! is_array($w) || count($w) !== 2 || ! array_is_list($w)
            || ! is_int($w[0]) || ! is_int($w[1]) || abs($w[0]) > self::CAP || abs($w[1]) > self::CAP) {
            $this->fail('params.routine_window_min', 'Must be an array of exactly 2 integers.');
        }
    }

    private function checkAgent(mixed $a, string $path): bool
    {
        if (! $a instanceof stdClass) {
            $this->fail($path, 'Must be an object.');

            return false;
        }
        if (! $this->keys($a, self::AGENT_KEYS, "$path.")) {
            return false;
        }

        if (! is_string($a->agent_id) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $a->agent_id) !== 1) {
            $this->fail("$path.agent_id", 'Must be 1–64 characters of A–Z, a–z, 0–9, "-" or "_".');
        }
        $this->string($a->name, "$path.name", 120);
        foreach (['est_tokens_total', 'est_io_tokens', 'turns', 'visible_chars', 'other_est_tokens'] as $k) {
            $this->int($a->$k, "$path.$k");
        }
        foreach (['K', 'K_io'] as $k) {
            $this->number($a->$k, "$path.$k");
        }

        if (! is_array($a->by_hour) || ! array_is_list($a->by_hour) || count($a->by_hour) !== 24) {
            $this->fail("$path.by_hour", 'Must be an array of exactly 24 integers.');
        } else {
            foreach ($a->by_hour as $h => $v) {
                $this->int($v, "$path.by_hour.$h");
            }
        }

        if (! is_array($a->by_routine) || ! array_is_list($a->by_routine)) {
            $this->fail("$path.by_routine", 'Must be an array.');
        } elseif (count($a->by_routine) > 200) {
            $this->fail("$path.by_routine", 'At most 200 routines.');
        } else {
            foreach ($a->by_routine as $j => $r) {
                $rp = "$path.by_routine.$j";
                if (! $r instanceof stdClass) {
                    $this->fail($rp, 'Must be an object.');

                    continue;
                }
                if (! $this->keys($r, self::ROUTINE_KEYS, "$rp.")) {
                    continue;
                }
                $this->string($r->routine_id, "$rp.routine_id", 120);
                $this->string($r->name, "$rp.name", 200);
                $this->int($r->runs, "$rp.runs");
                $this->int($r->est_tokens, "$rp.est_tokens");
            }
        }

        return true;
    }

    /** Exact key set: reports unknown and missing keys; false when the shape is unusable. */
    private function keys(stdClass $o, array $allowed, string $prefix): bool
    {
        $present = array_keys(get_object_vars($o));
        $ok = true;
        foreach (array_diff($present, $allowed) as $k) {
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

    private function number(mixed $v, string $path): void
    {
        if (! (is_int($v) || is_float($v)) || ! is_finite((float) $v) || $v < 0 || $v > self::CAP) {
            $this->fail($path, 'Must be a number between 0 and 1e15.');
        }
    }

    private function string(mixed $v, string $path, int $max): void
    {
        if (! is_string($v) || $v === '' || mb_strlen($v) > $max) {
            $this->fail($path, "Must be a string of 1–$max characters.");
        }
    }

    private function fail(string $path, string $message): void
    {
        if (count($this->errors) < self::MAX_ERRORS) {
            $this->errors[$path][] = $message;
        }
    }

    public static function isRealDate(string $s): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function isIsoWithOffset(string $s): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d)(\.\d{1,6})?)?(Z|[+-](0\d|1[0-4]):([0-5]\d))$/', $s, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
