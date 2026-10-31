<?php

namespace Tests\Feature\MultiUser;

use App\Models\Agent;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use App\Support\Workspaces\MissingWorkspace;
use Database\Seeders\BridgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkspaceScopeTest extends TestCase
{
    use RefreshDatabase;

    private function current(): CurrentWorkspace
    {
        return app(CurrentWorkspace::class);
    }

    private function other(): Workspace
    {
        return Workspace::query()->create(['slug' => 'other', 'name' => 'Other', 'owner_user_id' => User::factory()->create()->id]);
    }

    #[Test]
    public function console_rows_land_in_the_default_workspace_and_queries_are_unscoped(): void
    {
        $this->seed(BridgeSeeder::class);
        $tilen = $this->current()->default();

        $this->assertSame(2, Project::query()->where('workspace_id', $tilen->id)->count());
        $this->assertSame(5, Agent::query()->where('workspace_id', $tilen->id)->count());

        $other = $this->other();
        $this->current()->runAs($other, fn () => Agent::query()->create(['id' => 'agent-grok', 'name' => 'Grok', 'role_label' => 'Bot']));
        $this->assertSame(6, Agent::query()->count(), 'no workspace set in the console: unscoped');
    }

    #[Test]
    public function a_workspace_sees_and_writes_only_its_own_rows(): void
    {
        $this->seed(BridgeSeeder::class);
        $other = $this->other();

        $this->current()->runAs($other, function () use ($other) {
            $this->assertSame(0, Project::query()->count());
            $this->assertNull(Project::query()->find(BridgeSeeder::PROJ_MERKWELL));
            Agent::query()->create(['id' => 'agent-grok', 'name' => 'Grok', 'role_label' => 'Bot']);
            $p = Project::query()->create(['id' => 'proj-grok', 'name' => 'Merkwell Two', 'slug' => 'grok', 'owner_agent_id' => 'agent-grok']);
            $this->assertSame($other->id, $p->workspace_id);
            $this->assertNotSame('MW', $p->ticket_prefix, 'prefixes stay globally unique');
            $this->assertSame(['proj-grok'], Project::query()->pluck('id')->all());
        });

        $this->current()->runAs($this->current()->default(), function () {
            $this->assertEqualsCanonicalizing([BridgeSeeder::PROJ_MERKWELL, BridgeSeeder::PROJ_CONCIERGE], Project::query()->pluck('id')->all());
            $this->assertNull(Agent::query()->find('agent-grok'));
        });
    }

    #[Test]
    public function console_children_inherit_the_parent_projects_workspace(): void
    {
        $other = $this->other();
        $this->current()->runAs($other, function () {
            Agent::query()->create(['id' => 'agent-grok', 'name' => 'Grok', 'role_label' => 'Bot']);
            Project::query()->create(['id' => 'proj-grok', 'name' => 'Grok', 'slug' => 'grok', 'owner_agent_id' => 'agent-grok']);
        });

        $ticket = Ticket::query()->create(['id' => 'tk-x', 'project_id' => 'proj-grok', 'key' => 'GR-1', 'title' => 'T', 'status' => 'todo', 'created_by' => 'agent-grok', 'created_by_type' => 'agent']);

        $this->assertSame($other->id, $ticket->workspace_id);
    }

    #[Test]
    public function an_http_request_without_a_workspace_fails_closed(): void
    {
        $this->seed(BridgeSeeder::class);
        $this->current()->require();

        $this->expectException(MissingWorkspace::class);
        try {
            Project::query()->count();
        } finally {
            $this->current()->require(false);
        }
    }

    #[Test]
    public function creating_without_a_workspace_in_an_http_request_fails_closed(): void
    {
        $this->current()->require();

        try {
            Agent::query()->create(['id' => 'agent-grok', 'name' => 'Grok', 'role_label' => 'Bot']);
            $this->fail('expected MissingWorkspace');
        } catch (MissingWorkspace) {
        } finally {
            $this->current()->require(false);
        }
        $this->assertSame(0, Agent::query()->count());
    }
}
