<?php

namespace App\Mcp\Tools;

use App\Services\Tickets\TicketService;
use App\Services\Tickets\TicketView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('comment_ticket')]
#[Description('Add a comment to a ticket as your agent (same as POST /api/tickets/{key}/comments).')]
class CommentTicket extends BridgeTool
{
    protected const ABILITY = 'boards:write';

    public function __construct(private readonly TicketService $tickets) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Ticket key (e.g. CA-12).')->required(),
            'body' => $schema->string()->max(10000)->required(),
            'actor' => $this->actorField($schema),
        ];
    }

    protected function run(Request $request): array
    {
        $request->validate(['key' => ['required', 'string', 'max:64']]);
        $ticket = $this->ticket((string) $request->get('key'));
        $actor = $this->actor($request);
        $comment = $this->tickets->comment($ticket, $actor, $request->validate(TicketService::commentRules())['body']);

        return ['data' => (new TicketView)->comment($comment->fresh(), $ticket->fresh())];
    }
}
