<?php

namespace App\Mcp\Tools;

use App\Services\Tickets\OpenAsks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_open_asks')]
#[Description('A project\'s open asks for the workspace owner: live tickets in the waiting column with an ask, oldest first, with who asked and whether the answer awaits PM pickup (same as GET /api/projects/{id}/asks).')]
#[IsReadOnly]
class ListOpenAsks extends BridgeTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id or slug (list_projects).')->required(),
        ];
    }

    protected function run(Request $request): array
    {
        return ['data' => OpenAsks::list($this->project($request))];
    }
}
