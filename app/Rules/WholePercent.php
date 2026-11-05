<?php

namespace App\Rules;

use App\Services\PowerSaver\ThresholdSemantics;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Power Saver percent is a JSON integer from 0 to 100, or JSON null.
 * Numeric strings, empty strings, floats, booleans, and arrays are rejected.
 */
final class WholePercent implements ValidationRule
{
    /** Run for null and "" as well, so an empty string is not skipped and then cast to 0. */
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_int($value) || $value < ThresholdSemantics::MIN || $value > ThresholdSemantics::MAX) {
            $fail(__('power.errors.percent'));
        }
    }
}
