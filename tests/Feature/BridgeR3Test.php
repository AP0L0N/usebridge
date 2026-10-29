<?php

namespace Tests\Feature;

use App\Models\NextStep;
use App\Models\RequiredInput;
use App\Models\StatusUpdate;
use App\Models\User;
use App\Services\ProjectStatus;
use Database\Seeders\BridgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BridgeR3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->seed(BridgeSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function api(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    #[Test]
    public function portfolio_card_levels_follow_r3_thresholds(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);
        RequiredInput::query()->delete();
        RequiredInput::create(['id' => 'x', 'project_id' => 'proj-merkwell', 'text' => 'x', 'waiting_on' => 'D', 'since' => now()->subDays(4)]);
        $this->get('/')->assertInertia(fn (Assert $p) => $p->component('Portfolio')
            ->where('projects', fn ($ps) => collect($ps)->firstWhere('id', 'proj-merkwell')['level'] === 2));
    }

    #[Test]
    public function cleared_inputs_older_than_two_days_are_hidden(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);
        foreach ([['recent', 47], ['edge', 48], ['old', 49]] as [$id, $h]) {
            RequiredInput::create(['id' => $id, 'project_id' => 'proj-merkwell', 'text' => $id, 'waiting_on' => 'D',
                'since' => now()->subDays(5), 'resolved_at' => now()->subHours($h), 'resolved_by' => 'tilen']);
        }
        // Kanban: the 2-day rule still holds in the service; the board no longer shows inputs.
        $done = ProjectStatus::recentlyDone(RequiredInput::query()->where('project_id', 'proj-merkwell')->get(), 'resolved_at');
        $this->assertSame(['recent', 'edge'], $done->pluck('id')->all());
        $this->get('/projects/merkwell')->assertInertia(fn (Assert $p) => $p->component('Board')->missing('doneInputs'));
    }

    #[Test]
    public function timeline_pages_in_seven_day_steps(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);
        StatusUpdate::query()->where('project_id', 'proj-merkwell')->delete();
        foreach ([1, 6, 8, 13, 20] as $d) {
            StatusUpdate::create(['id' => "u$d", 'project_id' => 'proj-merkwell', 'agent_id' => StatusUpdate::first()?->agent_id ?? 'agent-anatoly', 'body' => "d$d", 'created_at' => now()->subDays($d)]);
        }
        // Kanban: the status-update feed is gone from the project page; ?days is ignored and the rows stay.
        foreach (['', '?days=14', '?days=-3'] as $qs) {
            $this->get('/projects/merkwell'.$qs)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Board')->missing('statusUpdates')->missing('timeline'));
        }
        $this->assertSame(5, StatusUpdate::query()->where('project_id', 'proj-merkwell')->count());
    }

    #[Test]
    public function next_steps_api_requires_sanctum(): void
    {
        $this->postJson('/api/next-steps', [])->assertUnauthorized();
        $this->patchJson('/api/next-steps/x', [])->assertUnauthorized();
        $this->getJson('/api/next-steps')->assertUnauthorized();
    }

    #[Test]
    public function next_steps_validation(): void
    {
        $this->api();
        $this->postJson('/api/next-steps', [])->assertStatus(422)->assertJsonValidationErrors(['project_id', 'kind', 'text']);
        $this->postJson('/api/next-steps', ['project_id' => 'nope', 'kind' => 'later', 'text' => str_repeat('a', 501)])
            ->assertStatus(422)->assertJsonValidationErrors(['project_id', 'kind', 'text']);
        $this->patchJson('/api/next-steps/missing', ['text' => 'x'])->assertNotFound();
    }

    #[Test]
    public function captain_can_post_patch_and_list_next_steps(): void
    {
        $this->api();
        $r = $this->postJson('/api/next-steps', ['project_id' => 'proj-merkwell', 'kind' => 'in_progress', 'text' => 'Build r3', 'owner' => 'Xerxes'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'in_progress')->assertJsonPath('data.done', false)->assertJsonPath('data.owner', 'Xerxes');
        $id = $r->json('data.id');
        $this->assertStringStartsWith('ns-', $id);
        $this->postJson('/api/next-steps', ['id' => 'ns-block-1', 'project_id' => 'proj-merkwell', 'kind' => 'blocker', 'text' => 'Waiting on DNS'])->assertCreated();
        $this->postJson('/api/next-steps', ['id' => 'ns-block-1', 'project_id' => 'proj-merkwell', 'kind' => 'blocker', 'text' => 'dup'])->assertStatus(422);

        $this->withSession(['bridge_gate_unlocked' => true]);
        $this->get('/projects/merkwell')->assertInertia(fn (Assert $p) => $p->component('Board')->missing('nextSteps'));

        $this->patchJson('/api/next-steps/ns-block-1', ['kind' => 'in_progress', 'text' => 'DNS done, verifying'])
            ->assertOk()->assertJsonPath('data.kind', 'in_progress')->assertJsonPath('data.text', 'DNS done, verifying');
        $this->patchJson("/api/next-steps/$id", ['done' => true, 'done_by' => 'captain'])
            ->assertOk()->assertJsonPath('data.done', true)->assertJsonPath('data.done_by', 'captain');
        $this->assertNotNull(NextStep::find($id)->done_at);

        $this->getJson('/api/next-steps?project_id=proj-merkwell')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/next-steps?project_id=proj-merkwell&state=all')->assertJsonCount(2, 'data');
        $this->getJson('/api/next-steps?state=done')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);

        $this->patchJson("/api/next-steps/$id", ['done' => false])->assertJsonPath('data.done', false)->assertJsonPath('data.done_by', null);
        $this->deleteJson("/api/next-steps/$id")->assertNoContent();
        $this->assertNull(NextStep::find($id));
    }
}
