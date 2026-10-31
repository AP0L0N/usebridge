<?php

namespace Tests\Feature\MultiUser;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\McpHelpers;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/** S10: GET/PATCH /api/projects/{slug} work for the token's own project only. */
class ApiProjectLookupTest extends TestCase
{
    use McpHelpers, RefreshDatabase, TicketHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
    }

    #[Test]
    public function a_project_is_found_by_id_or_slug_in_the_own_workspace_only(): void
    {
        $ws = app(CurrentWorkspace::class)->default();
        $token = $this->scopedToken($ws->owner, $ws, 'write', 'lookup');

        $this->bearer($token, 'GET', '/api/projects/'.self::BH)->assertOk()->assertJsonPath('data.slug', 'blue-harbor');
        $this->bearer($token, 'GET', '/api/projects/blue-harbor')->assertOk()->assertJsonPath('data.id', self::BH);
        $this->bearer($token, 'PATCH', '/api/projects/blue-harbor', ['name' => 'Blue Harbour'])->assertOk()->assertJsonPath('data.name', 'Blue Harbour');

        // Another workspace's slug is a 404 like a missing one.
        $other = User::factory()->create(['email' => 'kim@example.test']);
        $ows = Workspace::query()->create(['slug' => 'kim-ws', 'name' => 'Kim WS', 'owner_user_id' => $other->id]);
        Project::withoutGlobalScopes()->forceCreate(['id' => 'proj-kim', 'name' => 'Kim Secret', 'slug' => 'kim-secret', 'workspace_id' => $ows->id]);
        $this->bearer($token, 'GET', '/api/projects/kim-secret')->assertNotFound();
        $this->bearer($token, 'GET', '/api/projects/proj-kim')->assertNotFound();
        $this->bearer($this->scopedToken($other, $ows, 'read', 'kim'), 'GET', '/api/projects/kim-secret')->assertOk()->assertJsonPath('data.name', 'Kim Secret');
    }
}
