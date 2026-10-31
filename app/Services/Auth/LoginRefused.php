<?php

namespace App\Services\Auth;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refused login (docs/MULTI-USER-PLAN.md §8 "login wrong / locked"): 422 {message, tries_left} for a wrong password,
 * 429 {message, locked_until} while locked or IP-throttled, 403 for disabled / deletion-pending accounts.
 */
final class LoginRefused extends RuntimeException
{
    /** @param  array<string, mixed>  $extra */
    public function __construct(string $message, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), ...$this->extra], $this->status);
    }
}
