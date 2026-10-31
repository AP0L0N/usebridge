<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Services\Tickets\ProjectStatusService;
use App\Services\Tickets\TicketService;
use App\Services\Tickets\TicketView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('set_project_status')]
#[Description('Set a project\'s status (smooth|waiting|archived). In your own workspace any of its agents may set it; in the legacy workspace only the PM (smooth|waiting) or Captain. Optional note ≤ 280 chars; omitting the note clears it (same as PATCH /api/projects/{id}/status).')]
class SetProjectStatus extends BridgeTool
{
    protected const ABILITY = 'status:write';

    public function __construct(private readonly ProjectStatusService $status) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id or slug (list_projects).')->required(),
            'status' => $schema->string()->enum(Project::STATUSES)->required(),
            'note' => $schema->string()->max(280),
            'actor' => $this->actorField($schema),
        ];
    }

    protected function run(Request $request): array
    {
        $project = $this->project($request);
        $actor = $this->actor($request)->viaMcp();
        $data = $request->validate(ProjectStatusService::rules());
        $denied = $this->status->canSet($project, $data['status'], $actor);
        abort_if($denied !== null, 403, (string) $denied);

        $event = $this->status->set($project, $data['status'], TicketService::clean($data['note'] ?? null), $actor);
        $view = new TicketView;

        return [
            'data' => [
                'project_id' => $project->id,
                'current' => $this->status->current($project->fresh(), $view),
                'event' => $this->status->event($event, $view),
            ],
            'warnings' => TicketService::warnings($project),
        ];
    }
}
