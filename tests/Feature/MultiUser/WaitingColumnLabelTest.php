<?php

namespace Tests\Feature\MultiUser;

use App\Models\Agent;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\AccountService;
use App\Services\Tickets\TicketService;
use App\Support\BoardColumns;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\McpHelpers;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/**
 * The waiting_tilen column name (docs/MULTI-USER-PLAN.md §3): new workspaces show "Waiting on <owner's first name>"
 * (B2; "Waiting on you" only without an owner);
 * workspaces that existed at migration 2026_10_31_160000 keep their old name (workspaces.settings.waiting_label); a
 * project's own column_labels still win. The key stays waiting_tilen; labels are derived in code, never stored.
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

    #[Test]
    public function a_new_workspace_shows_waiting_on_its_owner_on_the_web_rest_and_mcp(): void
    {
        $this->assertNull($this->acme->waitingLabel());
        $this->assertSame(self::NEW_LABEL, $this->acme->waitingColumnLabel());
        $this->assertSame('Waiting on you', BoardColumns::LABELS[BoardColumns::WAITING_TILEN]); // no workspace at all

        $this->web()->get('/projects/demo-site')->assertInertia(fn (Assert $p) => $p
            ->where('board.columns.3.key', 'waiting_tilen')->where('board.columns.3.label', self::NEW_LABEL));
        $this->web()->get('/')->assertInertia(fn (Assert $p) => $p
            ->where('columns.3', ['key' => 'waiting_tilen', 'label' => self::NEW_LABEL]));

        $this->bearer($this->danaToken, 'GET', '/api/board-columns')->assertOk()
            ->assertJsonPath('data.3', ['key' => 'waiting_tilen', 'label' => self::NEW_LABEL, 'order' => 3]);
        $this->bearer($this->danaToken, 'POST', '/api/projects/proj-demo/tickets', ['title' => 'Approve the logo', 'status' => 'waiting_tilen'])
            ->assertUnprocessable()->assertJsonPath('errors.ask.0', 'Moving into Waiting on Dana needs an ask: what do you need answered?');
        $key = $this->bearer($this->danaToken, 'POST', '/api/projects/proj-demo/tickets', ['title' => 'Approve the logo', 'status' => 'waiting_tilen', 'ask' => 'Logo A or B?'])
            ->assertCreated()->assertJsonPath('data.status', 'waiting_tilen')->assertJsonPath('data.status_label', self::NEW_LABEL)->json('data.key');
        $this->bearer($this->danaToken, 'GET', "/api/tickets/$key")->assertJsonPath('data.status_label', self::NEW_LABEL);

        $this->assertSame(self::NEW_LABEL, $this->tool($this->danaToken, 'get_ticket', ['key' => $key])['data']['status_label']);
        $other = $this->tool($this->danaToken, 'create_ticket', ['project' => 'proj-demo', 'title' => 'Pick a font'])['data']['key'];
        $this->assertStringContainsString('Moving into Waiting on Dana needs an ask', $this->toolError($this->danaToken, 'move_ticket', ['key' => $other, 'status' => 'waiting_tilen']));
    }

    #[Test]
    public function the_existing_workspace_keeps_its_label_everywhere(): void
    {
        $legacy = $this->legacyLabel();

        $this->asOwner()->get('/projects/blue-harbor')->assertInertia(fn (Assert $p) => $p->where('board.columns.3.label', $legacy));
        $this->asOwner()->get('/')->assertInertia(fn (Assert $p) => $p->where('columns.3.label', $legacy));

        $this->api();
        $this->getJson('/api/board-columns')->assertJsonPath('data.3.label', $legacy);
        $this->postJson('/api/projects/'.self::BH.'/tickets', ['title' => 'Sign off', 'status' => 'waiting_tilen', 'actor' => self::JANICE])
            ->assertUnprocessable()->assertJsonPath('errors.ask.0', "Moving into $legacy needs an ask: what do you need answered?");
        $this->assertSame($legacy, $this->waitingTicket()['status_label']);

        $token = $this->scopedToken($this->owners()->owner, $this->owners(), 'write', 'owner-laptop');
        $this->assertSame($legacy, $this->tool($token, 'get_ticket', ['key' => 'BH-1'])['data']['status_label']);

        // Another workspace's setting never leaks into this one (or the other way round).
        $this->bearer($this->danaToken, 'GET', '/api/board-columns')->assertJsonPath('data.3.label', self::NEW_LABEL);
    }

    #[Test]
    public function a_projects_own_column_label_still_wins(): void
    {
        $this->web()->putJson('/projects/demo-site/column-labels', ['column_labels' => ['waiting_tilen' => 'Waiting on client']])->assertOk();
        $this->assertSame(['waiting_tilen' => 'Waiting on client'], Project::acrossWorkspaces()->findOrFail('proj-demo')->column_labels);

        $this->web()->get('/projects/demo-site')->assertInertia(fn (Assert $p) => $p->where('board.columns.3.label', 'Waiting on client'));
        $this->bearer($this->danaToken, 'POST', '/api/projects/proj-demo/tickets', ['title' => 'Approve the logo', 'status' => 'waiting_tilen'])
            ->assertJsonPath('errors.ask.0', 'Moving into Waiting on client needs an ask: what do you need answered?');
        // The workspace default (GET /api/board-columns) is unchanged.
        $this->bearer($this->danaToken, 'GET', '/api/board-columns')->assertJsonPath('data.3.label', self::NEW_LABEL);

        // The same on the existing workspace: the project's label wins over its legacy default.
        $this->legacyLabel();
        Project::acrossWorkspaces()->whereKey(self::BH)->update(['column_labels' => json_encode(['waiting_tilen' => 'Waiting on client'])]);
        $this->asOwner()->get('/projects/blue-harbor')->assertInertia(fn (Assert $p) => $p->where('board.columns.3.label', 'Waiting on client'));
        $this->assertSame('Moving into Waiting on client needs an ask: what do you need answered?', TicketService::askRequired(Project::acrossWorkspaces()->findOrFail(self::BH)));
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
