<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Concerns\FindsTickets;
use App\Http\Middleware\EnsureTokenAbility;
use App\Models\Project;
use App\Models\Ticket;
use App\Services\Tickets\Actor;
use App\Support\BoardColumns;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Base of every Bridge MCP tool (docs/MULTI-USER-PLAN.md §6). Runs inside POST /mcp after auth:sanctum and
 * ResolveTokenWorkspace, so every Eloquent query is scoped to the token's workspace exactly like /api. Each tool declares
 * the token ability it needs (same mapping as EnsureTokenAbility; '*' and 'ceo' pass); a missing ability is a tool
 * error, never data. Lookups reuse the REST helpers (FindsTickets), so another workspace's project or ticket gives the
 * same "Unknown …" error as one that does not exist. Validation errors come back as tool errors (laravel/mcp).
 */
abstract class BridgeTool extends Tool
{
    use FindsTickets;

    /** Token ability this tool needs. */
    protected const ABILITY = 'boards:read';

    public function handle(Request $request): Response
    {
        if (! EnsureTokenAbility::allows($request->user(), static::ABILITY)) {
            return Response::error(EnsureTokenAbility::lacks(static::ABILITY));
        }
        try {
            $result = $this->run($request);
        } catch (HttpExceptionInterface $e) {
            return Response::error($e->getMessage() !== '' ? $e->getMessage() : 'Not found.');
        } catch (ModelNotFoundException) {
            return Response::error('Not found.');
        }

        return Response::text(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> the JSON payload (same shapes as the REST API) */
    abstract protected function run(Request $request): array;

    /** The `project` argument: project id or slug, in this workspace only. */
    protected function project(Request $request): Project
    {
        $request->validate(['project' => ['required', 'string', 'max:255']]);

        return $this->projectById((string) $request->get('project'));
    }

    /** Schema of the `actor` argument (write tools). */
    protected function actorField(JsonSchema $schema): Type
    {
        return $schema->string()->description('Your agent id (list_agents). Not needed with a per-agent token; "tilen" is refused.');
    }

    /** Schema of the editable ticket fields (create_ticket / update_ticket); validated with TicketService::rules(). */
    protected function ticketFields(JsonSchema $schema): array
    {
        return [
            'description' => $schema->string()->max(20000),
            'status' => $schema->string()->enum(BoardColumns::keys())->description('Column key (create default: '.BoardColumns::TODO.').'),
            'assignee' => $schema->string()->description('Agent id, "tilen", or free text; empty to unassign.'),
            'priority' => $schema->string()->enum(Ticket::PRIORITIES),
            'due_on' => $schema->string()->format('date')->description('YYYY-MM-DD'),
            'blocked_reason' => $schema->string()->max(140),
            'labels' => $schema->array()->items($schema->string()->max(24))->max(20)->description('Label names (replaces the set).'),
            'ask' => $schema->string()->max(280)->description('Question for Tilen; required to enter waiting_tilen.'),
            'ask_context' => $schema->string()->max(500),
            'ask_options' => $schema->array()->items($schema->string()->max(60))->max(4)->description('Up to 4 answer choices.'),
        ];
    }

    /** The acting agent, as on the REST API: the per-agent token's name, else the `actor` argument. */
    protected function actor(Request $request): Actor
    {
        return Actor::forToken($request->get('actor'), $request->user());
    }
}
