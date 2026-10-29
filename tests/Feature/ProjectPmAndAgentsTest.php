<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Project;
use App\Models\ProjectStatusEvent;
use App\Services\BridgeRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/** Captain 29 Oct: Captain-only PM change (pm_agent_id), Captain-only agent creation, roster migration, list status. */
class ProjectPmAndAgentsTest extends TestCase
{
    use RefreshDatabase;
    use TicketHelpers;

    private const CP = 'proj-chatpulse';

    private const AE = 'proj-01m4rs1yd40cnfn1c681sw3ght';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
        foreach (['agent-alice' => 'Alice Murdoch', 'agent-angela' => 'Angela Loesser', 'agent-watts' => 'Dr. James R. Watts'] as $id => $name) {
            Agent::create(['id' => $id, 'name' => $name, 'role_label' => 'PM']);
        }
        Project::create(['id' => self::CP, 'name' => 'ChatPulse', 'slug' => 'chatpulse', 'owner_agent_id' => self::CAPTAIN]);
        Project::create(['id' => self::AE, 'name' => 'AdriaEdition', 'slug' => 'adriaedition', 'owner_agent_id' => self::CAPTAIN]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function captain_changes_the_pm_with_pm_agent_id_and_it_persists_with_history(): void
    {
        $this->api();
        $this->patchJson('/api/projects/'.self::CP, ['pm_agent_id' => 'agent-alice', 'actor' => self::CAPTAIN])->assertOk()
            ->assertJsonPath('data.pm_agent_id', 'agent-alice')->assertJsonPath('data.owner_agent_id', 'agent-alice')
            ->assertJsonPath('data.pmName', 'Alice Murdoch');
        $this->assertSame('agent-alice', Project::find(self::CP)->owner_agent_id);
        $this->getJson('/api/projects/'.self::CP)->assertOk()->assertJsonPath('data.pm_agent_id', 'agent-alice');
        $ev = DB::table('project_pm_events')->where('project_id', self::CP)->get();
        $this->assertCount(1, $ev);
        $this->assertSame([self::CAPTAIN, 'agent-alice', self::CAPTAIN, 'api'], [$ev[0]->from_agent_id, $ev[0]->to_agent_id, $ev[0]->set_by, $ev[0]->source]);

        // Same PM again: 200, no new event. owner_agent_id is the same field.
        $this->patchJson('/api/projects/'.self::CP, ['owner_agent_id' => 'agent-alice', 'actor' => self::CAPTAIN])->assertOk();
        $this->assertSame(1, DB::table('project_pm_events')->count());
    }

    #[Test]
    public function pm_change_is_captain_only_and_validated(): void
    {
        $this->api();
        $this->patchJson('/api/projects/'.self::CP, ['pm_agent_id' => 'agent-alice', 'actor' => 'agent-alice'])->assertForbidden();
        $this->patchJson('/api/projects/'.self::CP, ['owner_agent_id' => 'agent-alice', 'actor' => self::JANICE])->assertForbidden();
        $this->patchJson('/api/projects/'.self::CP, ['pm_agent_id' => 'agent-alice'])->assertUnprocessable()->assertJsonValidationErrors('actor');
        $this->patchJson('/api/projects/'.self::CP, ['pm_agent_id' => 'agent-nobody', 'actor' => self::CAPTAIN])->assertUnprocessable()->assertJsonValidationErrors('pm_agent_id');
        $this->patchJson('/api/projects/'.self::CP, ['pm_agent_id' => 'agent-alice', 'owner_agent_id' => self::JANICE, 'actor' => self::CAPTAIN])->assertUnprocessable();
        $this->assertSame(self::CAPTAIN, Project::find(self::CP)->owner_agent_id);
        $this->assertSame(0, DB::table('project_pm_events')->count());
    }

    #[Test]
    public function unknown_project_fields_are_a_422_not_a_silent_200(): void
    {
        $this->api();
        $this->patchJson('/api/projects/'.self::CP, ['status' => 'waiting', 'actor' => self::CAPTAIN])->assertUnprocessable()->assertJsonValidationErrors('fields');
        $this->patchJson('/api/projects/'.self::CP, ['pm' => 'agent-alice'])->assertUnprocessable()->assertJsonValidationErrors('fields');
        $this->patchJson('/api/projects/'.self::CP, ['name' => 'ChatPulse'])->assertOk(); // no actor needed for other fields
    }

    #[Test]
    public function alice_can_set_the_chatpulse_status_after_the_pm_change(): void
    {
        $this->api();
        $this->patchJson('/api/projects/'.self::CP.'/status', ['status' => 'waiting', 'actor' => 'agent-alice'])->assertForbidden();
        $this->patchJson('/api/projects/'.self::CP, ['pm_agent_id' => 'agent-alice', 'actor' => self::CAPTAIN])->assertOk();
        $this->patchJson('/api/projects/'.self::CP.'/status', ['status' => 'waiting', 'note' => 'Waiting on copy', 'actor' => 'agent-alice'])->assertOk()
            ->assertJsonPath('data.current.status', 'waiting')->assertJsonPath('data.current.set_by', 'agent-alice');
    }

    #[Test]
    public function list_and_show_return_the_current_pm_set_status(): void
    {
        $this->api();
        // No open inputs/questions: the old derived status is 'smooth'. The PM-set status must win.
        $this->patchJson('/api/projects/'.self::CP.'/status', ['status' => 'waiting', 'actor' => self::CAPTAIN])->assertOk();
        $row = collect($this->getJson('/api/projects')->assertOk()->json('data'))->firstWhere('id', self::CP);
        $this->assertSame(['waiting', 'waiting', 'smooth', 'pm', 1, 0], [$row['status'], $row['pmStatus'], $row['derived_status'], $row['status_source'], $row['level'], $row['ageDays']]);
        $this->getJson('/api/projects/'.self::CP)->assertJsonPath('data.status', 'waiting');

        $this->patchJson('/api/projects/'.self::CP.'/status', ['status' => 'smooth', 'actor' => self::CAPTAIN])->assertOk();
        $row = collect($this->getJson('/api/projects')->json('data'))->firstWhere('id', self::CP);
        $this->assertSame(['smooth', 'pm', null], [$row['status'], $row['status_source'], $row['level']]);

        // Never set: falls back to the derived status.
        $row = collect($this->getJson('/api/projects')->json('data'))->firstWhere('id', self::AE);
        $this->assertSame(['smooth', null, 'derived'], [$row['status'], $row['pmStatus'], $row['status_source']]);
    }

    #[Test]
    public function only_captain_can_create_agents(): void
    {
        $this->api();
        $this->postJson('/api/agents', ['id' => 'agent-zed', 'name' => 'Zed', 'actor' => self::JANICE])->assertForbidden();
        $this->postJson('/api/agents', ['id' => 'agent-zed', 'name' => 'Zed'])->assertUnprocessable()->assertJsonValidationErrors('actor');
        $this->postJson('/api/agents', ['id' => 'agent-zed', 'name' => 'Zed', 'actor' => self::CAPTAIN])->assertCreated()
            ->assertJsonPath('data.id', 'agent-zed')->assertJsonPath('data.role_label', 'Agent');
        $this->postJson('/api/agents', ['id' => 'agent-zed', 'name' => 'Zed again', 'actor' => self::CAPTAIN])->assertUnprocessable()->assertJsonValidationErrors('id');
        foreach (['Agent Zed', 'zed', 'agent-', 'agent--x', 'tilen', ''] as $bad) {
            $this->postJson('/api/agents', ['id' => $bad, 'name' => 'X', 'actor' => self::CAPTAIN])->assertUnprocessable()->assertJsonValidationErrors('id');
        }
        $this->assertSame('Zed', Agent::find('agent-zed')->name);
        $this->deleteJson('/api/agents/agent-zed')->assertStatus(405);
        $this->assertNotNull(Agent::find('agent-zed'));
    }

    #[Test]
    public function roster_migration_adds_agents_and_sets_pms_idempotently(): void
    {
        // Existing rows are never modified.
        Agent::create(['id' => 'agent-erin', 'name' => 'Erin (existing)', 'role_label' => 'Dev']);
        Project::query()->whereKey(self::MW)->update(['owner_agent_id' => self::JANICE]);
        Project::create(['id' => 'proj-chipolo', 'name' => 'Chipolo CraftCMS', 'slug' => 'chipolo', 'owner_agent_id' => 'agent-angela']);
        $before = Project::query()->orderBy('id')->get(['id', 'name', 'slug', 'status', 'archived_at'])->toArray();

        $this->artisan('bridge:set-pms', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull(Agent::find('agent-enrique'));
        $this->assertSame(self::CAPTAIN, Project::find(self::AE)->owner_agent_id);

        // What deploy runs: the migration's up() on a database that already has the table and projects.
        (include database_path('migrations/2026_10_29_120000_bridge_project_pm_events.php'))->up();

        foreach (['agent-enrique' => 'Enrique Cortez', 'agent-marc' => 'Marc Miller', 'agent-the-many' => 'The Many',
            'agent-constance' => 'Constance Sanger', 'agent-taz' => 'Taz Amanpour', 'agent-anatoly' => 'Anatoly Korenchkin'] as $id => $name) {
            $this->assertSame($name, Agent::find($id)?->name, $id);
        }
        $this->assertSame('Erin (existing)', Agent::find('agent-erin')->name);
        $pms = Project::query()->pluck('owner_agent_id', 'id');
        $this->assertSame('agent-enrique', $pms[self::AE]);
        $this->assertSame('agent-alice', $pms[self::CP]);
        $this->assertSame(self::ANATOLY, $pms[self::MW]);
        $this->assertSame(self::JANICE, $pms[self::CA]);
        $this->assertSame('agent-angela', $pms['proj-chipolo']);
        $this->assertSame($before, Project::query()->orderBy('id')->get(['id', 'name', 'slug', 'status', 'archived_at'])->toArray());
        $events = DB::table('project_pm_events')->count();
        $this->assertSame(3, $events); // AE, CP, MW changed; CA + Chipolo already right
        $this->assertSame('migration', DB::table('project_pm_events')->value('set_by'));

        // Second run changes nothing.
        $agents = Agent::count();
        $this->assertSame([], BridgeRoster::apply()['agents_created']);
        $this->artisan('bridge:set-pms')->assertSuccessful();
        $this->assertSame($agents, Agent::count());
        $this->assertSame($events, DB::table('project_pm_events')->count());
    }

    #[Test]
    public function enrique_can_write_after_the_migration(): void
    {
        $this->api();
        $this->postJson('/api/projects/'.self::AE.'/tickets', ['title' => 'Check the Adria feed', 'actor' => 'agent-enrique'])
            ->assertUnprocessable()->assertJsonValidationErrors('actor');

        $this->artisan('bridge:set-pms')->assertSuccessful();

        $this->postJson('/api/projects/'.self::AE.'/tickets', ['title' => 'Check the Adria feed', 'actor' => 'agent-enrique'])->assertCreated();
        $this->patchJson('/api/projects/'.self::AE.'/status', ['status' => 'smooth', 'note' => 'On track', 'actor' => 'agent-enrique'])->assertOk();
        $this->assertSame('agent-enrique', ProjectStatusEvent::query()->where('project_id', self::AE)->value('set_by'));
    }
}
