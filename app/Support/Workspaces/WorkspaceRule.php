<?php

namespace App\Support\Workspaces;

use Illuminate\Validation\Rules\Exists;

/** Validation rules on owned tables: `exists:` is a DB rule (no model scope), so it gets the workspace condition here. */
final class WorkspaceRule
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        $rule = new Exists($table, $column);
        $id = app(CurrentWorkspace::class)->id();

        return $id === null ? $rule : $rule->where('workspace_id', $id);
    }
}
