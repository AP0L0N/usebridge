<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Services\Tickets\BoardView;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_projects')]
#[Description('Projects of your workspace (id, name, slug, PM, ticket prefix, PM-set status, archived).')]
#[IsReadOnly]
class ListProjects extends BridgeTool
{
    protected function run(Request $request): array
    {
        $board = new BoardView;

        return ['data' => Project::query()->orderBy('name')->get()->map(fn (Project $p) => $board->project($p))->all()];
    }
}
