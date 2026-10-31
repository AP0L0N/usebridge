<?php

namespace App\Support\Workspaces;

use RuntimeException;

/** An HTTP request touched workspace-owned data before a workspace was resolved: fail closed, never unscoped. */
final class MissingWorkspace extends RuntimeException
{
    public function __construct(string $model = '')
    {
        parent::__construct('No workspace resolved for this request'.($model !== '' ? " ($model)" : '').'.');
    }
}
