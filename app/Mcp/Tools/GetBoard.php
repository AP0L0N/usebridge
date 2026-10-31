<?php

namespace App\Mcp\Tools;

use App\Services\Tickets\BoardView;
use App\Services\Tickets\TicketView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_board')]
#[Description('A project\'s Kanban board: the project and its columns with tickets (same as GET /api/projects/{id}/board). Done shows the last 7 days unless done_since is given.')]
#[IsReadOnly]
class GetBoard extends BridgeTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id or slug (list_projects).')->required(),
            'done_since' => $schema->string()->description('ISO date/time; Done tickets since then (default 7 days ago).'),
            'include_archived' => $schema->boolean()->description('Include archived tickets (default false).'),
        ];
    }

    protected function run(Request $request): array
    {
        $project = $this->project($request);
        $f = $request->validate([
            'done_since' => ['nullable', 'date'],
            'include_archived' => ['nullable', 'boolean'],
        ]);

        return ['data' => (new BoardView)->build(
            $project,
            empty($f['done_since']) ? null : TicketView::time($f['done_since']),
            (bool) ($f['include_archived'] ?? false),
        )];
    }
}
