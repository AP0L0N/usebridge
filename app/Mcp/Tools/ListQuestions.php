<?php

namespace App\Mcp\Tools;

use App\Services\Tickets\Questions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_questions')]
#[Description('A project\'s questions for the workspace owner (r4 Questions panel): {open, answered, closed, has_any}. A question is a ticket\'s current ask; state open (no answer yet), answered (owner input not picked up: ack it or move the ticket), closed (picked up or moved on). Same items as GET /api/projects/{id}/questions, except that a question the owner hid in the UI is reported as open.')]
#[IsReadOnly]
class ListQuestions extends BridgeTool
{
    private const STATES = ['open', 'answered', 'closed', 'all'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id or slug (list_projects).')->required(),
            'state' => $schema->string()->enum(self::STATES)->description('Only this state (default all).'),
        ];
    }

    protected function run(Request $request): array
    {
        $project = $this->project($request);
        $request->validate(['state' => ['nullable', Rule::in(self::STATES)]]);

        return ['data' => Questions::panel($project, null, (string) ($request->get('state') ?? 'all'), forAgents: true)];
    }
}
