<?php

namespace App\Mcp\Tools;

use App\Models\Agent;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_agents')]
#[Description('Agents of your workspace (id, name, role). The id is what `actor` and assignees use.')]
#[IsReadOnly]
class ListAgents extends BridgeTool
{
    protected function run(Request $request): array
    {
        return ['data' => Agent::query()->orderBy('name')->get()
            ->map(fn (Agent $a) => ['id' => $a->id, 'name' => $a->name, 'role_label' => $a->role_label])->all()];
    }
}
