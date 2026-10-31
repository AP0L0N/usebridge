<?php

namespace App\Mcp\Tools;

use App\Services\Tickets\TicketService;
use App\Services\Tickets\TicketView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_ticket')]
#[Description('Change a ticket\'s fields; only the fields you send change (same rules as PATCH /api/tickets/{key}). Use move_ticket to change column with placement.')]
class UpdateTicket extends BridgeTool
{
    protected const ABILITY = 'boards:write';

    public function __construct(private readonly TicketService $tickets) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Ticket key (e.g. CA-12).')->required(),
            'title' => $schema->string()->max(140),
            ...$this->ticketFields($schema),
            'actor' => $this->actorField($schema),
        ];
    }

    protected function run(Request $request): array
    {
        $request->validate(['key' => ['required', 'string', 'max:64']]);
        $ticket = $this->ticket((string) $request->get('key'));
        $actor = $this->actor($request);
        $ticket = $this->tickets->update($ticket, $request->validate(TicketService::rules(false)), $actor);

        return ['data' => (new TicketView)->ticket($ticket->fresh()), 'warnings' => TicketService::warnings($ticket->project)];
    }
}
