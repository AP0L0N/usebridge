<?php

namespace App\Services\Polymarket;

use RuntimeException;

/** A Polymarket feed payload that failed validation. Messages never echo submitted values. */
class InvalidPolymarketFeed extends RuntimeException
{
    /** @param array<string, list<string>> $errors path => messages */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The Polymarket feed is invalid.');
    }
}
