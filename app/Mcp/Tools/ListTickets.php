<?php

namespace App\Mcp\Tools;

use App\Models\Ticket;
use App\Services\Tickets\TicketView;
use App\Support\BoardColumns;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_tickets')]
#[Description('Tickets of your workspace (not archived), ordered by project, column and position. Same filters as GET /api/tickets.')]
#[IsReadOnly]
class ListTickets extends BridgeTool
{
    public const DEFAULT_LIMIT = 50;

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id or slug (list_projects).'),
            'status' => $schema->string()->description('Column key(s), comma-separated: '.implode(', ', BoardColumns::keys()).'.'),
            'label' => $schema->string()->description('Label name.'),
            'assignee' => $schema->string()->description('Agent id, "owner" (the workspace owner), a person id (user:…), or "none" for unassigned.'),
            'q' => $schema->string()->description('Text in the title or key.'),
            'limit' => $schema->integer()->min(1)->max(200)->description('Default '.self::DEFAULT_LIMIT.'.'),
        ];
    }

    protected function run(Request $request): array
    {
        $args = $request->all(['status', 'label', 'assignee', 'q', 'limit']);
        if ($request->get('project') !== null) {
            $args['project_id'] = $this->project($request)->id;
        }
        $f = validator($args, TicketView::filterRules())->validate();
        $q = TicketView::filter(Ticket::query()->with(TicketView::with()), $f)->limit((int) ($f['limit'] ?? self::DEFAULT_LIMIT));
        $view = new TicketView;

        return ['data' => $q->get()->map(fn (Ticket $t) => $view->ticket($t))->all()];
    }
}
