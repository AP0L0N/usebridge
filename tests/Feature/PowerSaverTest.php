<?php

namespace Tests\Feature;

use App\Models\PowerSaverThreshold;
use App\Models\Project;
use App\Models\ProjectStatusEvent;
use App\Models\UsageDay;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\BridgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Power Saver stores a stop percent. It does not archive, set status, or stop a project or routine. */
class PowerSaverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BridgeSeeder::class);
        Project::query()->whereKey(BridgeSeeder::PROJ_NORTHWIND)->update(['status' => 'smooth', 'archived_at' => null]);
        Project::query()->whereKey(BridgeSeeder::PROJ_BLUE_HARBOR)->update(['status' => 'archived', 'archived_at' => now()]);
        UsageDay::query()->create([
            'date' => '2037-10-13',
            'generated_at' => now(),
            'est_tokens_total' => 10,
            'payload' => [
                'agents' => [[
                    'agent_id' => 'agent-janice',
                    'name' => 'From file',
                    'by_routine' => [
                        ['routine_id' => 'unmatched', 'name' => 'No routine', 'runs' => 1, 'est_tokens' => 1],
                        ['routine_id' => 'other_est_tokens', 'name' => 'Other', 'runs' => 1, 'est_tokens' => 1],
                        ['routine_id' => 'support-inbox', 'name' => 'Support inbox', 'runs' => 2, 'est_tokens' => 8],
                    ],
                ]],
            ],
        ]);
    }

    #[Test]
    public function the_page_lists_projects_and_routines_and_says_the_contract_is_not_locked(): void
    {
        PowerSaverThreshold::factory()->routine('agent-tommy', 'weekday-desk')->percent(40)->create();

        $this->asOwner()->get(route('power-saver'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PowerSaver')
            ->where('semantics.locked', false)
            ->where('semantics.comparisonAgreed', false)
            ->where('semantics.comparison', 'proposed_gte')
            ->where('semantics.enforcement', 'not_implemented')
            ->where('semantics.mutatesSubjects', false)
            ->where('semantics.usageCeilingDefined', false)
            ->where('semantics.projectUsageAttribution', false)
            ->where('semantics.min', 0)
            ->where('semantics.max', 100)
            ->where('semantics.storedZeroMeans', 'threshold')
            ->where('semantics.nullMeans', 'no_threshold')
            ->where('gaps.projectEnabledState', false)
            ->where('gaps.routineEnabledState', false)
            ->where('gaps.routineRegistry', false)
            ->where('gaps.chosenStopOption', null)
            ->has('projects', 2)
            ->where('projects.0.id', BridgeSeeder::PROJ_BLUE_HARBOR)
            ->where('projects.0.archived', true)
            ->where('projects.0.stoppableInBridge', false)
            ->where('projects.0.stopPercent', null)
            ->where('projects.1.id', BridgeSeeder::PROJ_NORTHWIND)
            ->where('projects.1.status', 'smooth')
            ->has('routines', 2)
            ->where('routines.0.agentName', 'Dr. Janice Polito')
            ->where('routines.0.routineId', 'support-inbox')
            ->where('routines.0.seenInUsage', true)
            ->where('routines.0.stoppableInBridge', false)
            ->where('routines.1.agentId', 'agent-tommy')
            ->where('routines.1.seenInUsage', false)
            ->where('routines.1.stopPercent', 40));
    }

    #[Test]
    public function an_empty_workspace_has_empty_project_and_routine_lists(): void
    {
        $sam = User::factory()->create();
        Workspace::query()->create(['slug' => 'sam-ps', 'name' => 'Sam', 'owner_user_id' => $sam->id]);

        $this->actingAs($sam)->get(route('power-saver'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PowerSaver')
            ->has('projects', 0)
            ->has('routines', 0)
            ->where('gaps.chosenStopOption', null));
    }

    #[Test]
    public function a_guest_cannot_open_or_write_power_saver(): void
    {
        $this->get(route('power-saver'))->assertRedirect('/login');
        $this->patchJson(route('power-saver.update'), ['thresholds' => [[
            'subject_type' => 'project', 'project_id' => BridgeSeeder::PROJ_NORTHWIND, 'stop_percent' => 10,
        ]]])->assertRedirect('/login');
    }

    #[Test]
    public function saving_a_threshold_does_not_change_the_project(): void
    {
        $project = Project::query()->findOrFail(BridgeSeeder::PROJ_NORTHWIND);
        $before = [$project->status, $project->archived_at?->toIso8601String()];
        $events = ProjectStatusEvent::query()->where('project_id', $project->id)->count();

        $this->asOwner()->patchJson(route('power-saver.update'), ['thresholds' => [[
            'subject_type' => 'project', 'project_id' => $project->id, 'stop_percent' => 0,
        ]]])->assertOk()->assertJsonPath('data.0.stop_percent', 0);

        $project->refresh();
        $this->assertSame($before, [$project->status, $project->archived_at?->toIso8601String()]);
        $this->assertSame($events, ProjectStatusEvent::query()->where('project_id', $project->id)->count());
        $this->assertDatabaseHas('power_saver_thresholds', [
            'subject_type' => 'project', 'project_id' => $project->id, 'stop_percent' => 0,
        ]);

        $this->patchJson(route('power-saver.update'), ['thresholds' => [[
            'subject_type' => 'project', 'project_id' => $project->id, 'stop_percent' => 100,
        ]]])->assertOk()->assertJsonPath('data.0.stop_percent', 100);

        $this->patchJson(route('power-saver.update'), ['thresholds' => [[
            'subject_type' => 'project', 'project_id' => $project->id, 'stop_percent' => null,
        ]]])->assertOk()->assertJsonPath('data.0.stop_percent', null);

        $this->assertDatabaseMissing('power_saver_thresholds', ['project_id' => $project->id]);
        $project->refresh();
        $this->assertSame('smooth', $project->status);
        $this->assertNull($project->archived_at);
    }

    #[Test]
    public function a_member_can_save_and_the_boundaries_are_rejected(): void
    {
        $member = $this->workspaceUser();

        $this->actingAs($member)->patchJson(route('power-saver.update'), ['thresholds' => [[
            'subject_type' => 'routine', 'agent_id' => 'agent-anatoly', 'routine_id' => 'morning-digest', 'stop_percent' => 15,
        ]]])->assertOk()->assertJsonPath('data.0.seen_in_usage', false);

        foreach ([101, -1, 80.5, true, '80.5'] as $bad) {
            $this->patchJson(route('power-saver.update'), ['thresholds' => [[
                'subject_type' => 'project', 'project_id' => BridgeSeeder::PROJ_NORTHWIND, 'stop_percent' => $bad,
            ]]])->assertUnprocessable()->assertJsonValidationErrors('thresholds.0.stop_percent');
        }

        $this->assertDatabaseMissing('power_saver_thresholds', ['project_id' => BridgeSeeder::PROJ_NORTHWIND]);

        $this->patchJson(route('power-saver.update'), ['thresholds' => [
            ['subject_type' => 'project', 'project_id' => BridgeSeeder::PROJ_NORTHWIND, 'stop_percent' => 20],
            ['subject_type' => 'project', 'project_id' => 'proj-missing', 'stop_percent' => 20],
        ]])->assertUnprocessable();
        $this->assertDatabaseMissing('power_saver_thresholds', ['project_id' => BridgeSeeder::PROJ_NORTHWIND]);

        $this->patchJson(route('power-saver.update'), ['thresholds' => [[
            'subject_type' => 'routine', 'agent_id' => 'agent-janice', 'routine_id' => 'unmatched', 'stop_percent' => 10,
        ]]])->assertUnprocessable()->assertJsonValidationErrors('thresholds.0.routine_id');

        $this->patchJson(route('power-saver.update'), ['thresholds' => [[
            'subject_type' => 'routine', 'agent_id' => 'nope', 'routine_id' => 'ghost', 'stop_percent' => 10,
        ]]])->assertUnprocessable();
    }

    #[Test]
    public function the_read_api_reports_the_proposed_contract_and_does_not_write(): void
    {
        $reader = $this->workspaceUser()->createToken('read', ['boards:read'])->plainTextToken;

        $this->withToken($reader)->getJson(route('api.power-saver.show'))
            ->assertOk()
            ->assertJsonPath('data.semantics.locked', false)
            ->assertJsonPath('data.semantics.comparison', 'proposed_gte')
            ->assertJsonPath('data.semantics.comparison_agreed', false)
            ->assertJsonPath('data.semantics.enforcement', 'not_implemented')
            ->assertJsonPath('data.semantics.mutates_subjects', false)
            ->assertJsonPath('data.semantics.stored_zero_means', 'threshold')
            ->assertJsonPath('data.semantics.stored_hundred_means', 'full_ceiling')
            ->assertJsonPath('data.semantics.null_means', 'no_threshold')
            ->assertJsonPath('data.semantics.usage_ceiling_defined', false)
            ->assertJsonPath('data.semantics.project_usage_attribution', false)
            ->assertJsonPath('data.gaps.project_enabled_state', false)
            ->assertJsonPath('data.gaps.routine_enabled_state', false)
            ->assertJsonPath('data.gaps.chosen_stop_option', null)
            ->assertJsonPath('data.gaps.stop_options.0.id', 'external_only')
            ->assertJsonPath('data.gaps.stop_options.0.status', 'proposed')
            ->assertJsonPath('data.gaps.stop_options.1.id', 'future_power_hold')
            ->assertJsonPath('data.gaps.stop_options.1.status', 'not_built')
            ->assertJsonPath('data.gaps.stop_options.2.id', 'archive_or_status')
            ->assertJsonPath('data.gaps.stop_options.2.status', 'rejected')
            ->assertJsonPath('data.projects.1.id', BridgeSeeder::PROJ_NORTHWIND)
            ->assertJsonPath('data.projects.1.status', 'smooth')
            ->assertJsonPath('data.projects.1.stoppable_in_bridge', false)
            ->assertJsonPath('data.routines.0.routine_id', 'support-inbox')
            ->assertJsonPath('data.routines.0.agent_name', 'Dr. Janice Polito')
            ->assertJsonMissing(['routine_id' => 'unmatched']);

        $this->assertSame(0, PowerSaverThreshold::query()->count());

        $this->withToken($reader)->patchJson(route('api.power-saver.update'), ['thresholds' => [[
            'subject_type' => 'project', 'project_id' => BridgeSeeder::PROJ_NORTHWIND, 'stop_percent' => 5,
        ]]])->assertForbidden();
    }

    #[Test]
    public function a_write_token_can_set_a_threshold_and_a_read_token_cannot_read_without_boards_read(): void
    {
        $writer = $this->workspaceUser()->createToken('write', ['boards:write'])->plainTextToken;

        $this->withToken($writer)->getJson(route('api.power-saver.show'))->assertForbidden();
        $this->withToken($writer)->patchJson(route('api.power-saver.update'), ['thresholds' => [[
            'subject_type' => 'project', 'project_id' => BridgeSeeder::PROJ_NORTHWIND, 'stop_percent' => 55,
        ]]])->assertOk()->assertJsonPath('data.0.stop_percent', 55);

        $this->assertSame('smooth', Project::query()->find(BridgeSeeder::PROJ_NORTHWIND)->status);
    }

    #[Test]
    public function another_workspace_cannot_see_or_set_these_thresholds(): void
    {
        PowerSaverThreshold::factory()->percent(33)->create();
        $owner = User::factory()->create();
        Workspace::query()->create(['slug' => 'other-ps', 'name' => 'Other', 'owner_user_id' => $owner->id]);
        $token = $owner->createToken('other', ['*'])->plainTextToken;

        $this->withToken($token)->getJson(route('api.power-saver.show'))
            ->assertOk()
            ->assertJsonCount(0, 'data.projects')
            ->assertJsonCount(0, 'data.routines');

        $this->withToken($token)->patchJson(route('api.power-saver.update'), ['thresholds' => [[
            'subject_type' => 'project', 'project_id' => BridgeSeeder::PROJ_NORTHWIND, 'stop_percent' => 9,
        ]]])->assertUnprocessable();

        $this->assertDatabaseHas('power_saver_thresholds', [
            'project_id' => BridgeSeeder::PROJ_NORTHWIND, 'stop_percent' => 33,
        ]);
    }
}
