<?php

namespace App\Services\Usage;

use RuntimeException;

/** A usage day file that failed strict validation. Messages never echo submitted values. */
class InvalidUsageDay extends RuntimeException
{
    /** @param array<string, list<string>> $errors path => messages */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The usage day file is invalid.');
    }
}
