<?php

namespace App\Models\Concerns;

use App\Support\Workspaces\CurrentWorkspace;
use App\Support\Workspaces\MissingWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** `workspace_id = current` on every query of an owned model; throws when an HTTP request has no workspace. */
final class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $current = app(CurrentWorkspace::class);
        $id = $current->id();
        if ($id !== null) {
            $builder->where($model->qualifyColumn('workspace_id'), $id);
        } elseif ($current->isRequired()) {
            throw new MissingWorkspace($model::class);
        }
    }
}
