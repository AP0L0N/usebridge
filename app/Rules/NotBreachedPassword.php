<?php

namespace App\Rules;

use App\Services\Auth\BreachedPasswords;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Rejects passwords that appear in a known breach (HIBP range API, fail-open; see BreachedPasswords). */
final class NotBreachedPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && app(BreachedPasswords::class)->isBreached($value)) {
            $fail('This password has appeared in a data breach. Please choose a different one.');
        }
    }
}
