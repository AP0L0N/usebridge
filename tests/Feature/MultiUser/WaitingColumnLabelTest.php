<?php

namespace Tests\Feature\MultiUser;

use App\Models\Agent;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\AccountService;
use App\Services\Tickets\Actor;
use App\Services\Tickets\TicketService;
use App\Support\BoardColumns;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\McpHelpers;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/**
 * The waiting_tilen column is gone from every board (web, REST, MCP); "input needed" is the waiting_on_input flag. Its
 * name only survives for older history lines (docs/MULTI-USER-PLAN.md §3): "Waiting on <owner's first name>" for new
 * workspaces ("Waiting on you" only without an owner), the old name (workspaces.settings.waiting_label) for workspaces
 * that existed at migration 2026_10_31_160000.
 */
class WaitingColumnLabelTest extends TestCase
{
    use McpHelpers, RefreshDatabase, TicketHelpers;

    private const MIGRATION = 'migrations/2026_10_31_160000_workspace_settings.php';

    /** Dana Demo owns Acme Studio. */
    private const NEW_LABEL = 'Waiting on Dana';

    private User $dana;

    private Workspace $acme;

    private string $danaToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
        Notification::fake();
        $this->dana = app(AccountService::class)->register('Dana Demo', 'demo@example.test', 'acme-studio-planner-27', 'Acme Studio');
        $this->dana->markEmailAsVerified();
        $this->acme = Workspace::query()->where('owner_user_id', $this->dana->id)->firstOrFail();
        app(CurrentWorkspace::class)->runAs($this->acme, fn () => Agent::query()->create(['id' => 'agent-demo', 'name' => 'Demo Bot', 'role_label' => 'PM']));
        $seed = $this->dana->createToken('seed', ['*'])->plainTextToken;
        $this->bearer($seed, 'POST', '/api/projects', ['id' => 'proj-demo', 'name' => 'Demo Site', 'slug' => 'demo-site', 'ticket_prefix' => 'DEMO', 'owner_agent_id' => 'agent-demo', 'actor' => 'agent-demo'])->assertCreated();
        $this->danaToken = $this->scopedToken($this->dana, $this->acme, 'write', 'agent-demo');
    }

    /** Dana logged in on the web (bearer() switched the default guard to sanctum). */
    private function web(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->dana, 'web');
    }

    private function owners(): Workspace
    {
        return app(CurrentWorkspace::class)->default()->fresh();
    }

    /** What the existing workspace showed before (the backfill derives it from its owner's first name in tests). */
    private function legacyLabel(): string
    {
        $label = $this->owners()->waitingLabel();
        $this->assertNotNull($label);
        $this->assertSame('Waiting on '.strtok($this->owners()->owner->name, ' '), $label);
        $this->assertNotSame(self::NEW_LABEL, $label);

        return $label;
    }

    private function runMigration(): void
    {
        (require database_path(self::MIGRATION))->up();
    }

    /** Every row of every table, workspaces.settings left out. */
    private function snapshot(): array
    {
        $rows = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $rows[$table] = DB::table($table)->get()->map(fn ($r) => array_diff_key((array) $r, $table === 'workspaces' ? ['settings' => 1] : []))->all();
        }

        return $rows;
    }

    /** An older history row: the ticket was moved into the waiting column, back when it existed. */
    private function movedIntoWaiting(string $ticketKey): void
    {
        $ticket = Ticket::acrossWorkspaces()->where('key', $ticketKey)->firstOrFail();
        DB::table('ticket_events')->insert([
            'ticket_id' => $ticket->id, 'project_id' => $ticket->project_id, 'workstream_id' => $ticket->workstream_id, 'workspace_id' => $ticket->workspace_id,
            'type' => 'moved', 'from' => json_encode(['status' => 'todo', 'position' => 0]), 'to' => json_encode(['status' => 'waiting_tilen', 'position' => 0]),
            'actor_id' => 'migration', 'actor_type' => 'system', 'source' => 'api', 'created_at' => now(),
        ]);
    }

    #[Test]
    public function the_waiting_column_is_gone_from_the_web_rest_and_mcp(): void
    {
        $this->assertNotContains(BoardColumns::WAITING_TILEN, BoardColumns::keys());
        $five = ['backlog', 'todo', 'in_progress', 'review', 'done'];

        $this->web()->followingRedirects()->get('/projects/demo-site')->assertInertia(fn (Assert $p) => $p
            ->where('board.columns', fn ($cols) => collect($cols)->pluck('key')->all() === $five)
            ->where('defaultColumns', fn ($cols) => collect($cols)->pluck('key')->all() === $five));
        $this->web()->get('/')->assertInertia(fn (Assert $p) => $p
            ->where('columns', fn ($cols) => collect($cols)->pluck('key')->all() === $five));
        $this->asOwner()->followingRedirects()->get('/projects/blue-harbor')->assertInertia(fn (Assert $p) => $p
            ->where('board.columns', fn ($cols) => collect($cols)->pluck('label')->all() === ['Backlog', 'To do', 'In progress', 'Review / QA', 'Done']));

        $this->assertSame($five, array_column($this->bearer($this->danaToken, 'GET', '/api/board-columns')->assertOk()->json('data'), 'key'));
        $this->bearer($this->danaToken, 'POST', '/api/projects/proj-demo/tickets', ['title' => 'Approve the logo', 'status' => 'waiting_tilen', 'ask' => 'Logo A or B?'])
            ->assertUnprocessable()->assertJsonPath('errors.status.0', TicketService::WAITING_COLUMN_REMOVED);

        // MCP: not a move target, in the schema or at run time; asking goes through waiting_on_input.
        $tools = collect($this->mcp($this->danaToken, 'tools/list')->json('result.tools'))->keyBy('name');
        $this->assertSame($five, $tools['move_ticket']['inputSchema']['properties']['status']['enum']);
        $this->assertArrayHasKey('waiting_on_input', $tools['update_ticket']['inputSchema']['properties']);
        $other = $this->tool($this->danaToken, 'create_ticket', ['project' => 'proj-demo', 'title' => 'Pick a font'])['data']['key'];
        $this->assertStringContainsString(TicketService::WAITING_COLUMN_REMOVED, $this->toolError($this->danaToken, 'move_ticket', ['key' => $other, 'status' => 'waiting_tilen', 'ask' => 'Serif?']));
        $asked = $this->tool($this->danaToken, 'update_ticket', ['key' => $other, 'waiting_on_input' => true, 'ask' => 'Serif?'])['data'];
        $this->assertSame(['todo', true, 'Serif?'], [$asked['status'], $asked['waiting_on_input'], $asked['ask']]);
        $this->assertSame([$other], array_column($this->tool($this->danaToken, 'list_tickets', ['waiting_on_input' => true])['data'], 'key'));
        $this->assertFalse($this->tool($this->danaToken, 'update_ticket', ['key' => $other, 'waiting_on_input' => false])['data']['waiting_on_input']);
        $this->assertSame([], $this->tool($this->danaToken, 'list_tickets', ['waiting_on_input' => true])['data']);

        // The service refuses the key too, whatever the caller validated.
        $this->expectException(ValidationException::class);
        app(CurrentWorkspace::class)->runAs($this->acme, fn () => app(TicketService::class)->create(
            Project::query()->findOrFail('proj-demo'), ['title' => 'x', 'status' => BoardColumns::WAITING_TILEN, 'ask' => 'y'], Actor::migration()));
    }

    #[Test]
    public function older_history_still_names_the_removed_column_as_each_workspace_called_it(): void
    {
        $legacy = $this->legacyLabel();
        $this->assertSame('Waiting on you', BoardColumns::WAITING_TILEN_LABEL); // no workspace at all
        $this->assertSame(self::NEW_LABEL, BoardColumns::label(BoardColumns::WAITING_TILEN, $this->acme));
        $this->assertSame($legacy, BoardColumns::label(BoardColumns::WAITING_TILEN, $this->owners()));

        $mine = $this->api()->newTicket()['key'];
        $this->movedIntoWaiting($mine);
        $event = $this->getJson("/api/tickets/$mine/events")->assertOk()->json('data.0');
        $this->assertSame([['status' => 'todo', 'position' => 0], ['status' => 'waiting_tilen', 'position' => 0, 'status_label' => $legacy]], [$event['from'], $event['to']]);
        $this->asOwner()->get("/projects/blue-harbor/tickets/$mine")->assertInertia(fn (Assert $p) => $p->where('ticket.events.0.to.status_label', $legacy));

        // Another workspace's name never leaks into this one (or the other way round).
        $danas = $this->bearer($this->danaToken, 'POST', '/api/projects/proj-demo/tickets', ['title' => 'Approve the logo'])->assertCreated()->json('data.key');
        $this->movedIntoWaiting($danas);
        $this->bearer($this->danaToken, 'GET', "/api/tickets/$danas/events")->assertJsonPath('data.0.to.status_label', self::NEW_LABEL);
        $this->assertSame(self::NEW_LABEL, $this->tool($this->danaToken, 'get_ticket', ['key' => $danas])['data']['events'][0]['to']['status_label']);
    }

    #[Test]
    public function a_stored_label_for_the_removed_column_is_ignored_and_cannot_be_set(): void
    {
        $this->web()->putJson('/projects/demo-site/column-labels', ['column_labels' => ['waiting_tilen' => 'Waiting on client']])
            ->assertUnprocessable()->assertJsonValidationErrors('column_labels');
        $this->assertNull(Project::acrossWorkspaces()->findOrFail('proj-demo')->column_labels);

        // A label saved while the column existed stays in the row and changes nothing on the board; the others still win.
        Project::acrossWorkspaces()->whereKey(self::BH)->update(['column_labels' => json_encode(['waiting_tilen' => 'Waiting on client', 'review' => 'QA'])]);
        $this->asOwner()->followingRedirects()->get('/projects/blue-harbor')->assertInertia(fn (Assert $p) => $p
            ->where('board.columns', fn ($cols) => collect($cols)->pluck('label')->all() === ['Backlog', 'To do', 'In progress', 'QA', 'Done']));
    }

    #[Test]
    public function the_backfill_takes_the_configured_label_and_rewrites_no_row(): void
    {
        $this->api()->waitingTicket();
        DB::table('workspaces')->update(['settings' => null]);
        DB::table('workspaces')->where('id', $this->acme->id)->update(['settings' => json_encode(['waiting_label' => 'Waiting on the studio'])]);
        $before = $this->snapshot();

        config(['bridge.owner_waiting_label' => 'Waiting on Sam']);
        $this->runMigration();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame('Waiting on Sam', $this->owners()->waitingLabel());
        $this->assertSame('Waiting on the studio', $this->acme->fresh()->waitingLabel()); // an existing setting is kept

        $this->runMigration(); // idempotent
        $this->assertSame('Waiting on Sam', $this->owners()->waitingLabel());
    }

    #[Test]
    public function without_config_the_backfill_derives_the_old_label_from_the_owner(): void
    {
        DB::table('workspaces')->update(['settings' => null]);
        DB::table('users')->where('id', $this->owners()->owner_user_id)->update(['name' => 'Avery Example']);
        config(['bridge.owner_waiting_label' => '']);
        $this->runMigration();

        $this->assertSame('Waiting on Avery', $this->owners()->waitingLabel());
        $this->assertSame('Waiting on Avery', $this->acme->fresh()->waitingLabel()); // existed at migration time

        $later = app(AccountService::class)->register('Lee Later', 'later@example.test', 'later-studio-planner-27', 'Later Studio');
        $this->assertNull(Workspace::query()->where('owner_user_id', $later->id)->firstOrFail()->waitingLabel());
        $this->assertSame('Waiting on Lee', BoardColumns::label('waiting_tilen', Workspace::query()->where('owner_user_id', $later->id)->firstOrFail()));
    }
}
