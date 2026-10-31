<?php

namespace Tests\Feature\MultiUser;

use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\McpHelpers;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/** S9: JSON 404s say "Not found." (or our own "Unknown …" text), never "No query results for model [App\\…]". */
class JsonNotFoundTest extends TestCase
{
    use McpHelpers, RefreshDatabase, TicketHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
    }

    #[Test]
    public function json_404s_do_not_leak_model_class_names(): void
    {
        config(['app.debug' => false]); // as on stage / prod
        $ws = app(CurrentWorkspace::class)->default();
        $token = $this->scopedToken($ws->owner, $ws, 'write', 'lookup');
        $this->bearer($token, 'PATCH', '/api/agents/agent-nope', ['name' => 'X'])->assertNotFound()->assertExactJson(['message' => 'Not found.']);

        foreach (['GET /api/projects/nope', 'PATCH /api/projects/nope', 'DELETE /api/projects/nope', 'GET /api/tickets/XX-999', 'PATCH /api/agents/agent-nope'] as $call) {
            [$method, $uri] = explode(' ', $call);
            $res = $this->bearer($token, $method, $uri, []);
            $res->assertNotFound();
            $this->assertStringNotContainsString('App\\', $res->getContent(), $call);
            $this->assertStringNotContainsString('No query results', $res->getContent(), $call);
        }
    }
}
