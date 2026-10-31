<?php

namespace App\Mcp\Tools;

use App\Services\Tickets\TicketView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_ticket')]
#[Description('One ticket with its comments (oldest first) and newest events (same as GET /api/tickets/{key}).')]
#[IsReadOnly]
class GetTicket extends BridgeTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['key' => $schema->string()->description('Ticket key (e.g. CA-12) or id (tk-…).')->required()];
    }

    protected function run(Request $request): array
    {
        $request->validate(['key' => ['required', 'string', 'max:64']]);

        return ['data' => (new TicketView)->detail($this->ticket((string) $request->get('key')))];
    }
}
