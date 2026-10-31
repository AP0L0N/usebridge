<?php

namespace App\Models\Concerns;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workspace-owned model: global WorkspaceScope + workspace_id filled on create (current workspace; in the console the
 * parent project's/ticket's workspace, else the default one). Bulk `insert()` bypasses this: pass workspace_id
 * yourself (CurrentWorkspace::idForWrite()).
 *
 * @mixin Model
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function (Model $model) {
            if ($model->getAttribute('workspace_id') !== null) {
                return;
            }
            $current = app(CurrentWorkspace::class);
            $model->setAttribute('workspace_id', $current->id() ?? ($current->isRequired()
                ? $current->idForWrite($model::class) // throws: HTTP without a workspace
                : $model->parentWorkspaceId() ?? $current->idForWrite($model::class)));
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** Every workspace: console tools, uniqueness that is still global (slug, ticket prefix, ticket key). */
    public static function acrossWorkspaces(): Builder
    {
        return static::query()->withoutGlobalScope(WorkspaceScope::class);
    }

    /** Console writes (no current workspace): inherit from the parent row. */
    protected function parentWorkspaceId(): ?int
    {
        foreach (['project_id' => Project::class, 'ticket_id' => Ticket::class] as $fk => $class) {
            $parent = $this->getAttribute($fk);
            if ($parent !== null && $class !== static::class) {
                $id = $class::acrossWorkspaces()->whereKey($parent)->value('workspace_id');
                if ($id !== null) {
                    return (int) $id;
                }
            }
        }

        return null;
    }
}
