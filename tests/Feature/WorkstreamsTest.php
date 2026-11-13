<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workstream;
use App\Models\WorkstreamEvent;
use App\Models\WorkstreamView;
use App\Services\Tickets\Workstreams;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\McpHelpers;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/**
 * Workstreams (portfolio-workstreams README §2–§5): default "General", NOT NULL ticket assignment, delete with ticket
 * transfer, the audit log, name lookups through older names, badges per user, and the board filtered by ?ws=.
 */
class WorkstreamsTest extends TestCase
{
    use McpHelpers, RefreshDatabase, TicketHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function project(): Project
    {
        return Project::query()->findOrFail(self::BH);
    }

    private function general(): Workstream
    {
        return Workstream::query()->where('project_id', self::BH)->where('is_default', true)->firstOrFail();
    }

    private function addStream(string $name): Workstream
    {
        $id = $this->asOwner()->postJson('/projects/blue-harbor/workstreams', ['name' => $name])->assertCreated()->json('data.id');

        return Workstream::query()->findOrFail($id);
    }

    // ---------------------------------------------------------------- default workstream + NOT NULL

    #[Test]
    public function every_project_starts_with_one_default_general(): void
    {
        foreach (Project::all() as $project) {
            $streams = Workstream::query()->where('project_id', $project->id)->get();
            $this->assertCount(1, $streams);
            $this->assertSame(['General', true], [$streams[0]->name, $streams[0]->is_default]);
        }

        $id = $this->asOwner()->postJson('/projects', ['name' => 'Podcast site'])->assertCreated()->json('data.id');
        $this->assertSame(['General'], Workstream::query()->where('project_id', $id)->where('is_default', true)->pluck('name')->all());
        $this->assertSame('created', WorkstreamEvent::query()->where('project_id', $id)->value('type'));
    }

    #[Test]
    public function tickets_without_a_workstream_go_to_the_default_from_ui_api_and_mcp(): void
    {
        $ui = $this->asOwner()->postJson('/projects/blue-harbor/tickets', ['title' => 'From the board'])->assertCreated()->json('data');
        $api = $this->api()->newTicket();
        $token = $this->scopedToken($this->workspaceOwner(), app(CurrentWorkspace::class)->default(), 'write', self::JANICE);
        $mcp = $this->tool($token, 'create_ticket', ['project' => self::BH, 'title' => 'From MCP'])['data'];

        $general = $this->general();
        foreach ([$ui, $api, $mcp] as $t) {
            $this->assertSame([$general->id, 'General'], [$t['workstream_id'], $t['workstream']]);
        }
    }

    #[Test]
    public function a_project_without_a_default_gets_general_before_the_ticket_is_saved(): void
    {
        DB::table('workstreams')->where('project_id', self::BH)->delete();

        $t = $this->api()->newTicket();

        $ws = Workstream::query()->where('project_id', self::BH)->get();
        $this->assertCount(1, $ws);
        $this->assertSame(['General', true, $ws[0]->id], [$ws[0]->name, $ws[0]->is_default, $t['workstream_id']]);
    }

    #[Test]
    public function workstream_id_is_not_null_in_the_database(): void
    {
        $this->expectException(QueryException::class);
        DB::table('tickets')->insert(['id' => 'tk-raw', 'project_id' => self::BH, 'key' => 'BH-99', 'title' => 'Raw', 'status' => 'todo', 'created_by' => 'x', 'created_by_type' => 'agent']);
    }

    #[Test]
    public function the_migration_moves_existing_tickets_to_general_and_keeps_their_history(): void
    {
        // A real migrate run (no surrounding transaction) on its own SQLite file: every migration before this one,
        // pre-existing rows, then this one.
        $file = tempnam(sys_get_temp_dir(), 'ws-migration');
        config(['database.connections.ws_migration' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true]]);
        $files = glob(database_path('migrations/*.php'));
        $mine = database_path('migrations/2026_11_12_100000_bridge_workstreams.php');
        $migrator = app('migrator');
        $migrator->usingConnection('ws_migration', function () use ($migrator, $files, $mine) {
            $migrator->getRepository()->createRepository();
            $migrator->run(array_values(array_diff($files, [$mine])));
        });
        $db = DB::connection('ws_migration');
        foreach (['p-a' => 'A', 'p-b' => 'B'] as $id => $name) {
            $db->table('projects')->insert(['id' => $id, 'name' => $name, 'slug' => $id]);
            foreach ([1, 2] as $n) {
                $db->table('tickets')->insert(['id' => "tk-$id-$n", 'project_id' => $id, 'key' => "$name-$n", 'title' => 'T', 'status' => 'todo', 'created_by' => 'agent-x', 'created_by_type' => 'agent', 'created_at' => '2026-11-01 10:00:00']);
                $db->table('ticket_events')->insert(['ticket_id' => "tk-$id-$n", 'project_id' => $id, 'type' => 'created', 'actor_id' => 'agent-x', 'actor_type' => 'agent', 'source' => 'api', 'created_at' => '2026-11-01 10:00:00']);
                $db->table('ticket_comments')->insert(['ticket_id' => "tk-$id-$n", 'author_id' => 'agent-x', 'author_type' => 'agent', 'body' => 'Hi', 'created_at' => '2026-11-01 10:00:00']);
            }
        }

        $migrator->usingConnection('ws_migration', fn () => $migrator->run([$mine]));

        foreach (['p-a', 'p-b'] as $id) {
            $general = $db->table('workstreams')->where('project_id', $id)->get();
            $this->assertCount(1, $general);
            $this->assertSame(['General', 1], [$general[0]->name, (int) $general[0]->is_default]);
            $this->assertSame([$general[0]->id], $db->table('tickets')->where('project_id', $id)->distinct()->pluck('workstream_id')->all());
            $this->assertSame(2, $db->table('ticket_events')->where('workstream_id', $general[0]->id)->count());
        }
        $this->assertSame([4, 4, 4], [$db->table('tickets')->count(), $db->table('ticket_events')->count(), $db->table('ticket_comments')->count()], 'nothing is lost in the table rebuild');
        $this->assertTrue(collect($db->select('PRAGMA table_info(tickets)'))->firstWhere('name', 'workstream_id')->notnull === 1);

        DB::purge('ws_migration');
        @unlink($file);
    }

    // ---------------------------------------------------------------- add / rename / audit / lookups

    #[Test]
    public function names_are_unique_within_a_project_and_rename_is_logged(): void
    {
        $front = $this->addStream('Frontend');
        $this->postJson('/projects/blue-harbor/workstreams', ['name' => ' frontend '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/projects/blue-harbor/workstreams', ['name' => str_repeat('x', 61)])->assertUnprocessable();
        $this->postJson('/projects/northwind/workstreams', ['name' => 'Frontend'])->assertCreated();

        $this->patchJson("/projects/blue-harbor/workstreams/{$front->id}", ['name' => 'Web app'])
            ->assertOk()->assertJsonPath('data.name', 'Web app')->assertJsonPath('message', 'Renamed to "Web app". Agents will use the new name.');

        $owner = $this->workspaceOwner();
        $events = WorkstreamEvent::query()->where('workstream_id', $front->id)->orderBy('id')->get();
        $this->assertSame(['created', 'renamed'], $events->pluck('type')->all());
        $this->assertSame(['Frontend', 'Web app', $owner->id, null], [$events[1]->from_name, $events[1]->to_name, $events[1]->actor_user_id, $events[1]->actor_agent_id]);
        $this->assertSame(['Frontend'], $front->fresh()->renamed_from);
    }

    #[Test]
    public function the_default_can_be_renamed(): void
    {
        $general = $this->general();
        $this->asOwner()->patchJson("/projects/blue-harbor/workstreams/{$general->id}", ['name' => 'Everything else'])->assertOk();
        $this->assertTrue($general->fresh()->is_default);
    }

    #[Test]
    public function agents_resolve_workstreams_by_id_and_by_older_or_deleted_names_and_get_the_current_name(): void
    {
        $front = $this->addStream('Frontend');
        $mobile = $this->addStream('Mobile');
        $this->patchJson("/projects/blue-harbor/workstreams/{$front->id}", ['name' => 'Web app'])->assertOk();
        $this->deleteJson("/projects/blue-harbor/workstreams/{$mobile->id}", ['target_id' => $front->id])->assertOk();

        $token = $this->scopedToken($this->workspaceOwner(), app(CurrentWorkspace::class)->default(), 'write', self::JANICE);
        foreach ([$front->id, 'Web app', 'frontend', 'Mobile'] as $ref) {
            $t = $this->tool($token, 'create_ticket', ['project' => self::BH, 'title' => "Via $ref", 'workstream' => $ref])['data'];
            $this->assertSame([$front->id, 'Web app'], [$t['workstream_id'], $t['workstream']], $ref);
        }
        $this->assertStringContainsString('Unknown workstream', $this->toolError($token, 'create_ticket', ['project' => self::BH, 'title' => 'x', 'workstream' => 'ws-nope']));

        $list = $this->tool($token, 'list_workstreams', ['project' => 'blue-harbor'])['data'];
        $this->assertSame(['Web app', 'General'], array_column($list, 'name'));
        $this->assertSame(['Frontend'], $list[0]['renamed_from']);
        $this->assertSame(4, $list[0]['ticket_count']);

        $this->assertCount(4, $this->tool($token, 'list_tickets', ['project' => self::BH, 'workstream' => 'Frontend'])['data']);
    }

    #[Test]
    public function an_agent_filing_into_a_new_name_creates_the_workstream_and_move_ticket_can_move_it(): void
    {
        $token = $this->scopedToken($this->workspaceOwner(), app(CurrentWorkspace::class)->default(), 'write', self::JANICE);
        $t = $this->tool($token, 'create_ticket', ['project' => self::BH, 'title' => 'Ship it', 'workstream' => 'Ops'])['data'];
        $ops = Workstream::query()->where('name', 'Ops')->firstOrFail();
        $this->assertSame($ops->id, $t['workstream_id']);
        $this->assertSame(self::JANICE, WorkstreamEvent::query()->where('workstream_id', $ops->id)->value('actor_agent_id'));

        $moved = $this->tool($token, 'move_ticket', ['key' => $t['key'], 'status' => 'in_progress', 'workstream' => 'General'])['data'];
        $this->assertSame(['General', 'in_progress'], [$moved['workstream'], $moved['status']]);
    }

    // ---------------------------------------------------------------- delete with transfer

    #[Test]
    public function deleting_a_workstream_with_tickets_needs_a_target_and_moves_them_to_an_existing_one(): void
    {
        $mobile = $this->addStream('Mobile');
        $front = $this->addStream('Frontend');
        foreach (['A', 'B', 'C'] as $title) {
            $this->postJson('/projects/blue-harbor/tickets', ['title' => $title, 'workstream' => $mobile->id])->assertCreated();
        }

        $this->deleteJson("/projects/blue-harbor/workstreams/{$mobile->id}")->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->deleteJson("/projects/blue-harbor/workstreams/{$mobile->id}", ['target_id' => $mobile->id])->assertUnprocessable();
        $this->deleteJson("/projects/blue-harbor/workstreams/{$mobile->id}", ['target_id' => $front->id])
            ->assertOk()->assertJsonPath('data.moved', 3)->assertJsonPath('data.target.name', 'Frontend');

        $this->assertSoftDeleted('workstreams', ['id' => $mobile->id]);
        $this->assertSame(3, Ticket::query()->where('workstream_id', $front->id)->count());
        $this->assertSame(3, Ticket::count(), 'tickets are never deleted');
        $e = WorkstreamEvent::query()->where('workstream_id', $mobile->id)->where('type', 'deleted')->firstOrFail();
        $this->assertSame(['Mobile', 'Frontend', $front->id, 3], [$e->from_name, $e->to_name, $e->merged_into_id, $e->ticket_count]);

        // Ticket history: "Moved from Mobile (deleted) → Frontend".
        $detail = $this->get('/projects/blue-harbor/tickets/BH-1')->assertOk()->viewData('page')['props']['ticket'];
        $move = collect($detail['events'])->firstWhere('type', 'workstream');
        $this->assertSame(['Mobile', true, 'Frontend'], [$move['from']['name'], $move['from']['deleted'], $move['to']['name']]);
    }

    #[Test]
    public function deleting_into_a_new_workstream_creates_it_first(): void
    {
        $mobile = $this->addStream('Mobile');
        $this->postJson('/projects/blue-harbor/tickets', ['title' => 'A', 'workstream' => $mobile->id])->assertCreated();

        $this->deleteJson("/projects/blue-harbor/workstreams/{$mobile->id}", ['new_name' => 'Mobile apps'])
            ->assertOk()->assertJsonPath('data.target.name', 'Mobile apps')->assertJsonPath('data.moved', 1);
        $new = Workstream::query()->where('name', 'Mobile apps')->firstOrFail();
        $this->assertSame(1, Ticket::query()->where('workstream_id', $new->id)->count());
        $this->assertSame(['created'], WorkstreamEvent::query()->where('workstream_id', $new->id)->pluck('type')->all());
    }

    #[Test]
    public function an_empty_non_default_workstream_is_a_simple_delete(): void
    {
        $spikes = $this->addStream('Spikes');
        $this->deleteJson("/projects/blue-harbor/workstreams/{$spikes->id}")->assertOk()->assertJsonPath('data.target', null);
        $this->assertSoftDeleted('workstreams', ['id' => $spikes->id]);
    }

    #[Test]
    public function the_only_or_default_workstream_cannot_be_deleted_without_a_fallback(): void
    {
        $general = $this->general();
        $this->asOwner()->postJson('/projects/blue-harbor/tickets', ['title' => 'A'])->assertCreated();

        // Only workstream: an existing target is impossible, a new name is required.
        $this->deleteJson("/projects/blue-harbor/workstreams/{$general->id}")->assertUnprocessable()->assertJsonValidationErrors('new_name');
        $this->assertNotSoftDeleted('workstreams', ['id' => $general->id]);

        // Default with another stream: the target takes the tickets and becomes the default.
        $ops = $this->addStream('Operations');
        $this->deleteJson("/projects/blue-harbor/workstreams/{$general->id}")->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->deleteJson("/projects/blue-harbor/workstreams/{$general->id}", ['target_id' => $ops->id])->assertOk();
        $this->assertTrue($ops->fresh()->is_default);
        $this->assertSame(1, Workstream::query()->where('project_id', self::BH)->where('is_default', true)->count());
        $this->assertSame(1, Ticket::query()->where('workstream_id', $ops->id)->count());
    }

    #[Test]
    public function archived_projects_are_read_only_for_workstreams(): void
    {
        $this->asOwner()->postJson('/projects/blue-harbor/archive')->assertOk();
        $this->postJson('/projects/blue-harbor/workstreams', ['name' => 'Late'])->assertStatus(409);
        $this->patchJson('/projects/blue-harbor/workstreams/'.$this->general()->id, ['name' => 'X'])->assertStatus(409);
    }

    #[Test]
    public function workstream_writes_need_a_login(): void
    {
        $this->postJson('/projects/blue-harbor/workstreams', ['name' => 'Ops'])->assertUnauthorized();
        $this->assertSame(1, Workstream::query()->where('project_id', self::BH)->count());
    }

    // ---------------------------------------------------------------- badges

    #[Test]
    public function badges_count_others_changes_since_the_last_view_and_opening_the_board_clears_them(): void
    {
        $owner = $this->workspaceOwner();
        $front = $this->addStream('Frontend');
        $back = $this->addStream('Backend');
        $this->travelMinutes(1);

        // The owner's own actions never count.
        $mine = $this->asOwner()->postJson('/projects/blue-harbor/tickets', ['title' => 'Mine', 'workstream' => $front->id])->json('data');
        $this->assertSame(0, Workstreams::badges([$front->id], $owner)[$front->id] ?? 0);

        // An agent: created (1), moved column (2), reorder only (no), commented (3), assigned (4).
        $t = $this->api()->newTicket(['workstream' => $front->id]);
        $this->postJson("/api/tickets/{$t['key']}/move", ['status' => 'in_progress', 'actor' => self::JANICE])->assertOk();
        $this->postJson("/api/tickets/{$t['key']}/move", ['status' => 'in_progress', 'position' => 0, 'actor' => self::JANICE])->assertOk();
        $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => 'Started', 'actor' => self::JANICE])->assertCreated();
        $this->patchJson("/api/tickets/{$t['key']}", ['assignee' => self::ANATOLY, 'actor' => self::JANICE])->assertOk();
        // Moved into Backend: counts there.
        $this->patchJson("/api/tickets/{$mine['key']}", ['workstream' => 'Backend', 'actor' => self::JANICE])->assertOk();
        // A rename of the workstream itself does not count.
        $this->asOwner()->patchJson("/projects/blue-harbor/workstreams/{$front->id}", ['name' => 'Web'])->assertOk();

        $this->assertSame([$front->id => 4, $back->id => 1], Workstreams::badges([$front->id, $back->id], $owner));

        // Previewing the project in the Finder does not clear anything.
        $this->travelMinutes(1);
        $this->get('/portfolio?project=blue-harbor')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('preview.total', 5)
            ->where('preview.workstreams', 2)
            ->has('preview.changes', 5)
            ->where('preview.changes.0.type', 'workstream')
            ->where('preview.changes.0.fromWorkstream', 'Frontend')
            ->where('projects', fn ($projects) => collect($projects)->firstWhere('id', self::BH)['hasChanges'] === true));
        $this->assertSame(4, Workstreams::badges([$front->id], $owner)[$front->id]);

        // Opening Frontend's board clears Frontend only, right away.
        $this->get("/projects/blue-harbor?ws={$front->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('workstream', $front->id)
            ->where('workstreams', fn ($ws) => collect($ws)->pluck('badge', 'name')->all() === ['Web' => 0, 'Backend' => 1, 'General' => 0]));
        $this->assertNotNull(WorkstreamView::query()->where(['user_id' => $owner->id, 'workstream_id' => $front->id])->value('last_viewed_at'));
        $this->assertSame([$back->id => 1], Workstreams::badges([$front->id, $back->id], $owner));

        // Opening Backend too: the project dot clears.
        $this->get("/projects/blue-harbor?ws={$back->id}")->assertOk();
        $this->get('/portfolio')->assertInertia(fn (Assert $p) => $p
            ->where('projects', fn ($projects) => collect($projects)->firstWhere('id', self::BH)['hasChanges'] === false));
    }

    #[Test]
    public function badges_are_per_user_and_archived_projects_show_none(): void
    {
        $front = $this->addStream('Frontend');
        $this->travelMinutes(1);
        $this->api()->newTicket(['workstream' => $front->id]);
        $sam = User::factory()->create();

        $this->assertSame(1, Workstreams::badges([$front->id], $this->workspaceOwner())[$front->id]);
        $this->assertSame(1, Workstreams::badges([$front->id], $sam)[$front->id]);
        WorkstreamView::query()->create(['user_id' => $sam->id, 'workstream_id' => $front->id, 'last_viewed_at' => now()]);
        $this->assertSame([], Workstreams::badges([$front->id], $sam));
        $this->assertSame(1, Workstreams::badges([$front->id], $this->workspaceOwner())[$front->id]);

        $this->asOwner()->postJson('/projects/blue-harbor/archive')->assertOk();
        $this->get('/portfolio')->assertInertia(fn (Assert $p) => $p->where('projects', function ($projects) {
            $bh = collect($projects)->firstWhere('id', self::BH);

            return $bh['hasChanges'] === false && collect($bh['workstreams'])->sum('badge') === 0;
        }));
    }

    // ---------------------------------------------------------------- board filter

    #[Test]
    public function the_board_shows_only_the_selected_workstreams_tickets(): void
    {
        $front = $this->addStream('Frontend');
        $this->postJson('/projects/blue-harbor/tickets', ['title' => 'In front', 'workstream' => $front->id])->assertCreated();
        $this->postJson('/projects/blue-harbor/tickets', ['title' => 'In general'])->assertCreated();
        $titles = fn (array $board) => collect($board['columns'])->flatMap(fn ($c) => array_column($c['tickets'], 'title'))->all();

        $this->get("/projects/blue-harbor?ws={$front->id}")->assertInertia(fn (Assert $p) => $p
            ->where('workstream', $front->id)
            ->where('board', fn ($b) => $titles($b->toArray()) === ['In front'])
            ->where('workstreams', fn ($ws) => collect($ws)->pluck('name')->all() === ['Frontend', 'General']));
        // A name (current or older) works too; no ?ws = the first tab.
        $this->get('/projects/blue-harbor?ws=frontend')->assertInertia(fn (Assert $p) => $p->where('workstream', $front->id));
        $this->get('/projects/blue-harbor')->assertInertia(fn (Assert $p) => $p->where('workstream', $front->id));
        $general = $this->general();
        $this->get("/projects/blue-harbor?ws={$general->id}")->assertInertia(fn (Assert $p) => $p
            ->where('board', fn ($b) => $titles($b->toArray()) === ['In general']));
        // A ticket deep link opens its own workstream.
        $this->get('/projects/blue-harbor/tickets/BH-2')->assertInertia(fn (Assert $p) => $p->where('workstream', $general->id));
    }

    #[Test]
    public function reorder_and_make_default(): void
    {
        $a = $this->addStream('Alpha');
        $b = $this->addStream('Beta');
        $this->postJson("/projects/blue-harbor/workstreams/{$b->id}/reorder", ['direction' => 'up'])->assertOk()
            ->assertJsonPath('data.*.name', ['Beta', 'Alpha', 'General']);
        $this->postJson("/projects/blue-harbor/workstreams/{$a->id}/default")->assertOk();
        $this->assertSame(['Alpha'], Workstream::query()->where('project_id', self::BH)->where('is_default', true)->pluck('name')->all());
    }
}
