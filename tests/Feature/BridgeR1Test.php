<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\RequiredInput;
use App\Models\User;
use App\Services\ProjectStatus;
use Database\Seeders\BridgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BridgeR1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 20:00:00');
        $this->seed(BridgeSeeder::class);
        Sanctum::actingAs(User::factory()->create(), ['ceo']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function post_update(array $extra = [], string $project = 'proj-merkwell')
    {
        return $this->postJson('/api/status-updates', [
            'project_id' => $project, 'agent_id' => 'agent-anatoly', 'body' => 'Note', ...$extra,
        ]);
    }

    #[Test]
    public function r0_payload_still_ingests_unchanged(): void
    {
        $this->postJson('/api/status-updates', [
            'project_id' => 'proj-merkwell', 'agent_id' => 'agent-anatoly', 'body' => 'Plain r0 post',
        ])->assertCreated()
            ->assertJsonPath('data.body', 'Plain r0 post')
            ->assertJsonPath('data.project_id', 'proj-merkwell')
            ->assertJsonStructure(['data' => ['id', 'project_id', 'agent_id', 'body', 'created_at']]);
        $this->postJson('/api/status-updates', ['project_id' => 'nope', 'agent_id' => 'agent-anatoly', 'body' => 'x'])
            ->assertUnprocessable();
    }

    #[Test]
    public function inputs_can_be_requested_and_resolved_through_updates(): void
    {
        $r = $this->post_update(['inputs' => [['text' => 'Region photos', 'waiting_on' => 'Denis']]])->assertCreated();
        $id = $r->json('inputs.0.id');
        $this->assertSame('waiting', ProjectStatus::derive(Project::with('requiredInputs')->find('proj-merkwell'))['status']);

        $this->post_update(['resolved_inputs' => [$id]])->assertCreated()->assertJsonPath('resolved_inputs.0.id', $id);
        $in = RequiredInput::find($id);
        $this->assertNotNull($in->resolved_at);
        $this->assertSame('Anatoly Korenchkin', $in->resolved_by);
        $this->assertSame('smooth', ProjectStatus::derive(Project::with('requiredInputs')->find('proj-merkwell'))['status']);

        $this->patchJson("/api/inputs/{$id}", ['done' => false])->assertOk()->assertJsonPath('data.open', true);
    }

    #[Test]
    public function age_levels_come_from_the_oldest_open_input(): void
    {
        $p = Project::find('proj-merkwell');
        $mk = fn ($id, $hoursAgo, $done = null) => RequiredInput::create([
            'id' => $id, 'project_id' => $p->id, 'text' => $id, 'waiting_on' => 'X',
            'since' => now()->subHours($hoursAgo), 'resolved_at' => $done,
        ]);
        $level = fn () => ProjectStatus::derive($p->fresh()->load('requiredInputs'));

        $mk('a', 5);
        $this->assertSame(['status' => 'waiting', 'level' => 1, 'ageDays' => 0], $level());   // <1d yellow
        $mk('b', 30);
        $this->assertSame(['status' => 'waiting', 'level' => 1, 'ageDays' => 1], $level());   // 1d yellow
        $mk('c', 50);
        $this->assertSame(['status' => 'waiting', 'level' => 1, 'ageDays' => 2], $level());   // 2d still yellow (r3)
        $mk('d', 24 * 9, now());                                                              // done: ignored
        $this->assertSame(1, $level()['level']);
        $mk('e', 75);
        $this->assertSame(['status' => 'waiting', 'level' => 2, 'ageDays' => 3], $level());   // 3d orange
        $mk('f', 24 * 6 + 20);
        $this->assertSame(['status' => 'waiting', 'level' => 2, 'ageDays' => 6], $level());   // 6d orange
        $mk('g', 24 * 7 + 1);
        $this->assertSame(['status' => 'waiting', 'level' => 3, 'ageDays' => 7], $level());   // >6d red
        RequiredInput::query()->whereIn('id', ['a', 'b', 'c', 'e', 'f', 'g'])->update(['resolved_at' => now()]);
        $this->assertSame('smooth', $level()['status']);
    }

    #[Test]
    public function archive_unarchive_and_auto_unarchive_with_reopened_tag(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);
        $this->post('/projects/merkwell/archive')->assertRedirect();
        $p = Project::find('proj-merkwell');
        $this->assertNotNull($p->archived_at);
        $this->assertSame('archived', ProjectStatus::summary($p->load('statusUpdates', 'requiredInputs'))['status']);

        $this->post('/projects/merkwell/unarchive')->assertRedirect();
        $this->assertNull(Project::find('proj-merkwell')->archived_at);
        $this->assertFalse(ProjectStatus::isReopened(Project::find('proj-merkwell')), 'manual unarchive is not "Reopened"');

        // API archive, then a new update auto-unarchives and sets Reopened.
        $this->postJson('/api/projects/proj-merkwell/archive')->assertOk()->assertJsonPath('data.status', 'archived');
        $this->post_update()->assertCreated()->assertJsonPath('unarchived', true);
        $p = Project::find('proj-merkwell');
        $this->assertNull($p->archived_at);
        $this->assertTrue(ProjectStatus::isReopened($p));

        // Reopened clears after 7 days...
        Carbon::setTestNow(now()->addDays(7)->addMinute());
        $this->assertFalse(ProjectStatus::isReopened($p->fresh()));
        Carbon::setTestNow(now()->subDays(6));
        $this->assertTrue(ProjectStatus::isReopened($p->fresh()));
        // ...or on the next update, whichever comes first.
        $this->post_update()->assertCreated()->assertJsonPath('unarchived', false);
        $this->assertNull(Project::find('proj-merkwell')->reopened_at);
    }

    #[Test]
    public function portfolio_props_include_archived_for_search_and_search_corpus(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);
        Project::find('proj-merkwell')->update(['archived_at' => now()]);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Portfolio')
            ->has('projects', 2)
            ->where('projects', fn ($ps) => collect($ps)->firstWhere('id', 'proj-merkwell')['archived'] === true
                && str_contains(collect($ps)->firstWhere('id', 'proj-merkwell')['searchText'], 'No open blockers')));
    }

    #[Test]
    public function removed_r0_endpoints_answer_410_and_legacy_project_fields_are_ignored(): void
    {
        foreach (['/api/milestones', '/api/work-items', '/api/risks', '/api/ops-digest', '/api/risks/x'] as $u) {
            $this->getJson($u)->assertStatus(410);
        }
        $this->postJson('/api/projects', [
            'name' => 'New', 'slug' => 'new', 'owner_agent_id' => 'agent-william',
            'goal' => 'old field', 'phase' => 'old', 'phase_done_looks_like' => 'old', 'next_deadline' => '2026-10-20',
        ])->assertCreated()->assertJsonPath('data.status', 'smooth');
    }

    #[Test]
    public function first_line_strips_markdown_and_html(): void
    {
        $this->assertSame('Round 6 update shipped to the client', ProjectStatus::firstLine("\n**Round 6 update shipped** to the [client](https://x)\n- more"));
        $this->assertSame('hi alert(1)', ProjectStatus::firstLine('<b>hi</b> alert(1)'));
    }

    #[Test]
    public function r0_ceo_queue_posts_become_inputs_and_dismiss_assign_work(): void
    {
        $r = $this->postJson('/api/ceo-queue-items', [
            'reason' => 'Pricing question', 'suggested_action' => 'Decide tax display', 'project_id' => 'proj-merkwell',
        ])->assertCreated()->assertJsonPath('data.status', 'open');
        $id = $r->json('data.id');
        $this->assertStringStartsWith('ceo-', $id);
        $this->assertDatabaseHas('required_inputs', ['id' => 'in-q-'.$id, 'waiting_on' => 'Tilen', 'text' => 'Decide tax display']);
        $this->postJson("/api/ceo-queue-items/{$id}/assign", ['agent_id' => 'agent-anatoly'])->assertOk()->assertJsonPath('data.waiting_on', 'Anatoly Korenchkin');
        $this->postJson("/api/ceo-queue-items/{$id}/dismiss")->assertOk()->assertJsonPath('data.status', 'dismissed');
        $this->getJson('/api/ceo-queue-items?status=dismissed')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/ceo-queue-items', ['reason' => 'x', 'suggested_action' => 'y'])->assertUnprocessable();
    }

    #[Test]
    public function r0_needs_decision_patch_maps_to_an_input(): void
    {
        $this->patchJson('/api/projects/proj-merkwell', ['needs_decision' => 'Pick a date'])->assertOk()->assertJsonPath('data.status', 'waiting');
        $this->patchJson('/api/projects/proj-merkwell', ['needs_decision' => null])->assertOk()->assertJsonPath('data.status', 'smooth');
    }
}
