<?php

namespace App\Mcp\Tools;

use App\Services\Tickets\TicketService;
use App\Services\Tickets\TicketView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_ticket')]
#[Description('Create a ticket on a project (same rules as POST /api/projects/{id}/tickets). Returns the ticket and project warnings.')]
class CreateTicket extends BridgeTool
{
    protected const ABILITY = 'boards:write';

    public function __construct(private readonly TicketService $tickets) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id or slug (list_projects).')->required(),
            'title' => $schema->string()->max(140)->required(),
            ...$this->ticketFields($schema),
            'actor' => $this->actorField($schema),
        ];
    }

    protected function run(Request $request): array
    {
        $project = $this->project($request);
        $actor = $this->actor($request)->viaMcp();
        $ticket = $this->tickets->create($project, $request->validate(TicketService::rules(true)), $actor);

        return ['data' => (new TicketView)->ticket($ticket->fresh()), 'warnings' => TicketService::warnings($project)];
    }
}
