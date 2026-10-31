<?php

namespace App\Mcp\Tools;

use App\Services\Tickets\TicketService;
use App\Services\Tickets\TicketView;
use App\Support\BoardColumns;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('move_ticket')]
#[Description('Move a ticket to a column, optionally at a position (same rules as POST /api/tickets/{key}/move). Moving into waiting_tilen needs an ask.')]
class MoveTicket extends BridgeTool
{
    protected const ABILITY = 'boards:write';

    public function __construct(private readonly TicketService $tickets) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Ticket key (e.g. CA-12).')->required(),
            'status' => $schema->string()->enum(BoardColumns::keys())->required(),
            'position' => $schema->integer()->min(0)->description('0-based position in the column (default: end).'),
            'before_id' => $schema->string()->description('Place before this ticket id.'),
            'after_id' => $schema->string()->description('Place after this ticket id.'),
            'ask' => $schema->string()->max(280)->description('Question for Tilen (needed for waiting_tilen).'),
            'ask_context' => $schema->string()->max(500),
            'ask_options' => $schema->array()->items($schema->string()->max(60))->max(4),
            'actor' => $this->actorField($schema),
        ];
    }

    protected function run(Request $request): array
    {
        $request->validate(['key' => ['required', 'string', 'max:64']]);
        $ticket = $this->ticket((string) $request->get('key'));
        $actor = $this->actor($request);
        $ticket = $this->tickets->move($ticket, $request->validate(TicketService::moveRules()), $actor);

        return ['data' => (new TicketView)->ticket($ticket->fresh()), 'warnings' => TicketService::warnings($ticket->project)];
    }
}
