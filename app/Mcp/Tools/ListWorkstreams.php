<?php

namespace App\Mcp\Tools;

use App\Models\Ticket;
use App\Models\Workstream;
use App\Services\Tickets\Workstreams;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_workstreams')]
#[Description('Workstreams of a project in board order (the default last), with their current names, older names and live ticket counts. Ticket writes take a workstream id or name; older names still resolve to the current workstream.')]
#[IsReadOnly]
class ListWorkstreams extends BridgeTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id or slug (list_projects).')->required(),
        ];
    }

    protected function run(Request $request): array
    {
        $project = $this->project($request);
        Workstreams::ensureDefault($project);
        $counts = Ticket::query()->where('project_id', $project->id)->whereNull('archived_at')
            ->selectRaw('workstream_id, count(*) as n')->groupBy('workstream_id')->pluck('n', 'workstream_id');

        return ['data' => Workstreams::ordered($project)->map(fn (Workstream $w) => [
            ...Workstreams::present($w),
            'ticket_count' => (int) ($counts[$w->id] ?? 0),
        ])->all()];
    }
}
