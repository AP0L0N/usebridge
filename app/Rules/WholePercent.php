<?php

namespace App\Rules;

use App\Services\PowerSaver\ThresholdSemantics;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A Power Saver percent: null (no threshold) or a whole number from 0 to 100. Floats and blanks are rejected. */
final class WholePercent implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        $whole = is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1);
        if (! $whole) {
            $fail(__('power.errors.percent'));

            return;
        }

        $n = (int) $value;
        if ($n < ThresholdSemantics::MIN || $n > ThresholdSemantics::MAX) {
            $fail(__('power.errors.percent'));
        }
    }
}
