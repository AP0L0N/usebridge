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
    public function the_page_lists_projects_and_routines_under_the_locked_contract(): void
    {
        PowerSaverThreshold::factory()->routine('agent-tommy', 'weekday-desk')->percent(40)->create();

        $this->asOwner()->get(route('power-saver'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PowerSaver')
            ->where('semantics.locked', true)
            ->where('semantics.comparisonAgreed', true)
            ->where('semantics.comparison', 'gte')
            ->where('semantics.comparisonRule', 'current_usage >= stop_percent')
            ->where('semantics.resume', 'strict_lt')
            ->where('semantics.resumeRule', 'current_usage < stop_percent')
            ->where('semantics.weeklyResetReenables', true)
            ->where('semantics.ceiling', 'shared_weekly_ultra_usage_limit')
            ->where('semantics.ceilingScope', 'shared_across_plan_apps')
            ->where('semantics.enforcement', 'external_only')
            ->where('semantics.mutatesSubjects', false)
            ->where('semantics.bridgeComputesCurrentUsage', false)
            ->where('semantics.usageCeilingDefined', true)
            ->where('semantics.projectUsageAttribution', 'approximate_agent_total_not_split')
            ->where('semantics.projectUsage.splitUsage', false)
            ->where('semantics.routineUsage.unobservedCurrentUsage', 0)
            ->where('semantics.projectAgentId.cardinality', 'at_most_one')
            ->where('semantics.projectAgentIds.cardinality', 'zero_or_one')
            ->where('semantics.min', 0)
            ->where('semantics.max', 100)
            ->where('semantics.storedZeroMeans', 'stop_at_or_above_zero')
            ->where('semantics.nullMeans', 'never_stops')
            ->where('gaps.projectEnabledState', false)
            ->where('gaps.routineEnabledState', false)
            ->where('gaps.bridgeCanPauseRoutines', false)
            ->where('gaps.projectUsageIsApproximate', true)
            ->where('gaps.chosenStopOption', 'external_only')
            ->has('projects', 2)
            ->where('projects.0.id', BridgeSeeder::PROJ_BLUE_HARBOR)
            ->where('projects.0.archived', true)
            ->where('projects.0.agentId', 'agent-janice')
            ->where('projects.0.agentIds', ['agent-janice'])
            ->where('projects.0.projectIdsForAgent', [BridgeSeeder::PROJ_BLUE_HARBOR])
            ->where('projects.0.splitUsage', false)
            ->where('projects.0.stoppableInBridge', false)
            ->where('projects.0.stopPercent', null)
            ->where('projects.1.id', BridgeSeeder::PROJ_NORTHWIND)
            ->where('projects.1.status', 'smooth')
            ->where('projects.1.agentId', 'agent-anatoly')
            ->where('projects.1.agentIds', ['agent-anatoly'])
            ->has('routines', 2)
            ->where('routines.0.agentName', 'Dr. Janice Polito')
            ->where('routines.0.agentId', 'agent-janice')
            ->where('routines.0.routineId', 'support-inbox')
            ->where('routines.0.seenInUsage', true)
            ->where('routines.0.usageSignal', 'own_routine')
            ->where('routines.0.unobservedCurrentUsage', 0)
            ->where('routines.0.stoppableInBridge', false)
            ->where('routines.1.agentId', 'agent-tommy')
            ->where('routines.1.routineId', 'weekday-desk')
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
            ->where('gaps.chosenStopOption', 'external_only'));
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
    public function the_read_api_reports_the_locked_contract_and_does_not_write(): void
    {
        $reader = $this->workspaceUser()->createToken('read', ['boards:read'])->plainTextToken;

        $this->withToken($reader)->getJson(route('api.power-saver.show'))
            ->assertOk()
            ->assertJsonPath('data.semantics.locked', true)
            ->assertJsonPath('data.semantics.comparison', 'gte')
            ->assertJsonPath('data.semantics.comparison_rule', 'current_usage >= stop_percent')
            ->assertJsonPath('data.semantics.resume_rule', 'current_usage < stop_percent')
            ->assertJsonPath('data.semantics.weekly_reset_reenables', true)
            ->assertJsonPath('data.semantics.ceiling', 'shared_weekly_ultra_usage_limit')
            ->assertJsonPath('data.semantics.ceiling_not', ['grok_bot_only_percentage', 'additional_credits'])
            ->assertJsonPath('data.semantics.enforcement', 'external_only')
            ->assertJsonPath('data.semantics.enforcement_actor', 'each_agent_pauses_and_resumes_its_own_routines')
            ->assertJsonPath('data.semantics.mutates_subjects', false)
            ->assertJsonPath('data.semantics.stored_zero_means', 'stop_at_or_above_zero')
            ->assertJsonPath('data.semantics.stored_hundred_means', 'stop_only_when_fully_used')
            ->assertJsonPath('data.semantics.null_means', 'never_stops')
            ->assertJsonPath('data.semantics.usage_ceiling_defined', true)
            ->assertJsonPath('data.semantics.bridge_computes_current_usage', false)
            ->assertJsonPath('data.semantics.project_usage_attribution', 'approximate_agent_total_not_split')
            ->assertJsonPath('data.semantics.project_usage.split_usage', false)
            ->assertJsonPath('data.semantics.project_agent_id.cardinality', 'at_most_one')
            ->assertJsonPath('data.semantics.project_agent_ids.cardinality', 'zero_or_one')
            ->assertJsonPath('data.semantics.routine_usage.unobserved_current_usage', 0)
            ->assertJsonPath('data.gaps.project_enabled_state', false)
            ->assertJsonPath('data.gaps.routine_enabled_state', false)
            ->assertJsonPath('data.gaps.bridge_can_pause_routines', false)
            ->assertJsonPath('data.gaps.project_usage_is_approximate', true)
            ->assertJsonPath('data.gaps.chosen_stop_option', 'external_only')
            ->assertJsonPath('data.gaps.stop_options.0.id', 'external_only')
            ->assertJsonPath('data.gaps.stop_options.0.status', 'locked')
            ->assertJsonPath('data.gaps.stop_options.1.id', 'enabled_flag')
            ->assertJsonPath('data.gaps.stop_options.1.status', 'rejected')
            ->assertJsonPath('data.gaps.stop_options.2.id', 'archive_or_status')
            ->assertJsonPath('data.gaps.stop_options.2.status', 'rejected')
            ->assertJsonPath('data.projects.0.agent_id', 'agent-janice')
            ->assertJsonPath('data.projects.0.agent_ids', ['agent-janice'])
            ->assertJsonPath('data.projects.1.id', BridgeSeeder::PROJ_NORTHWIND)
            ->assertJsonPath('data.projects.1.status', 'smooth')
            ->assertJsonPath('data.projects.1.agent_id', 'agent-anatoly')
            ->assertJsonPath('data.projects.1.stoppable_in_bridge', false)
            ->assertJsonPath('data.routines.0.routine_id', 'support-inbox')
            ->assertJsonPath('data.routines.0.agent_id', 'agent-janice')
            ->assertJsonPath('data.routines.0.agent_name', 'Dr. Janice Polito')
            ->assertJsonPath('data.routines.0.usage_signal', 'own_routine')
            ->assertJsonPath('data.routines.0.unobserved_current_usage', 0)
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
    public function one_agent_who_owns_several_projects_is_listed_on_each_and_usage_is_not_split(): void
    {
        Project::query()->whereKey(BridgeSeeder::PROJ_NORTHWIND)->update(['owner_agent_id' => 'agent-janice']);

        $token = $this->workspaceUser()->createToken('read', ['boards:read'])->plainTextToken;
        $projects = collect($this->withToken($token)->getJson(route('api.power-saver.show'))->assertOk()->json('data.projects'))
            ->keyBy('id');
        $shared = [BridgeSeeder::PROJ_BLUE_HARBOR, BridgeSeeder::PROJ_NORTHWIND];
        sort($shared);

        foreach ([BridgeSeeder::PROJ_BLUE_HARBOR, BridgeSeeder::PROJ_NORTHWIND] as $id) {
            $this->assertSame('agent-janice', $projects[$id]['agent_id']);
            $this->assertSame(['agent-janice'], $projects[$id]['agent_ids']);
            $this->assertSame($shared, $projects[$id]['project_ids_for_agent']);
            $this->assertFalse($projects[$id]['split_usage']);
            $this->assertTrue($projects[$id]['usage_approximation']);
            $this->assertSame('agent_total_estimated', $projects[$id]['usage_signal']);
        }
        $this->assertSame('smooth', $projects[BridgeSeeder::PROJ_NORTHWIND]['status']);
    }

    #[Test]
    public function a_project_with_no_pm_exposes_an_empty_agent_list(): void
    {
        Project::query()->whereKey(BridgeSeeder::PROJ_NORTHWIND)->update(['owner_agent_id' => null]);

        $token = $this->workspaceUser()->createToken('read', ['boards:read'])->plainTextToken;
        $project = collect($this->withToken($token)->getJson(route('api.power-saver.show'))->assertOk()->json('data.projects'))
            ->firstWhere('id', BridgeSeeder::PROJ_NORTHWIND);

        $this->assertNull($project['agent_id']);
        $this->assertSame([], $project['agent_ids']);
        $this->assertSame([], $project['project_ids_for_agent']);
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
