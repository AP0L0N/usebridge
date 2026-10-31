<?php

namespace App\Mcp\Tools;

use App\Services\StatusUpdateIngest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('post_status')]
#[Description('Post a status update on a project as your agent (same as POST /api/status-updates). Optional: new required inputs and inputs resolved. Un-archives an archived project.')]
class PostStatus extends BridgeTool
{
    protected const ABILITY = 'status:write';

    public function __construct(private readonly StatusUpdateIngest $ingest) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id or slug (list_projects).')->required(),
            'body' => $schema->string()->max(5000)->required(),
            'inputs' => $schema->array()->max(50)->items($schema->object([
                'text' => $schema->string()->max(500)->required(),
                'waiting_on' => $schema->string()->max(120)->required(),
                'since' => $schema->string()->format('date-time'),
            ]))->description('New open required inputs.'),
            'resolved_inputs' => $schema->array()->max(50)->items($schema->string())->description('Ids of required inputs now done.'),
            'actor' => $this->actorField($schema),
        ];
    }

    protected function run(Request $request): array
    {
        $project = $this->project($request);
        $actor = $this->actor($request);
        $data = $request->validate(StatusUpdateIngest::rules());
        $result = $this->ingest->handle($project, $actor->id, $data['body'], null, $data['inputs'] ?? [], $data['resolved_inputs'] ?? [], 'mcp');

        return [
            'data' => $result['update'],
            'inputs' => $result['inputs'],
            'resolved_inputs' => $result['resolved'],
            'unarchived' => $result['unarchived'],
        ];
    }
}
