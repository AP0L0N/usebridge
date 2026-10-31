<?php

namespace Tests\Feature\Screens;

use App\Models\Agent;
use App\Models\Project;
use App\Models\ProjectStatusEvent;
use App\Models\User;
use App\Services\Auth\AccountService;
use App\Support\BoardColumns;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Redesigned Portfolio: the props that drive each state (empty, search, archived), the bot line and the shell gating. */
class PortfolioScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $sam;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->sam = app(AccountService::class)->register('Sam Rivera', 'sam@example.test', 'garden-planner-27');
    }

    private function project(User $user, string $name, array $attrs = []): Project
    {
        $ws = $user->workspaces()->first() ?? \App\Models\Workspace::query()->where('owner_user_id', $user->id)->firstOrFail();

        $id = $user->is($this->sam) ? 'agent-ava' : 'agent-lee-pm'; // agents.id is unique across workspaces

        return app(CurrentWorkspace::class)->runAs($ws, function () use ($name, $attrs, $id) {
            $agent = Agent::query()->firstOrCreate(['id' => $id], ['name' => 'Ava', 'role_label' => 'PM']);

            return Project::query()->create([
                'id' => 'proj-'.str($name)->slug(), 'name' => $name, 'slug' => str($name)->slug()->toString(),
                'owner_agent_id' => $agent->id, 'ticket_prefix' => strtoupper(substr(str($name)->slug('')->toString(), 0, 3)), ...$attrs,
            ]);
        });
    }

    private function event(User $user, Project $p, string $source): void
    {
        app(CurrentWorkspace::class)->runAs($p->workspace_id ? \App\Models\Workspace::query()->findOrFail($p->workspace_id) : $user->workspaces()->first(), fn () => ProjectStatusEvent::query()->create([
            'id' => 'pse-'.$p->id.$source, 'project_id' => $p->id, 'to_status' => 'smooth', 'set_by' => 'agent-ava', 'set_by_type' => 'agent', 'source' => $source, 'created_at' => now(),
        ]));
    }

    #[Test]
    public function an_empty_portfolio_renders_with_no_projects_and_the_empty_copy_exists(): void
    {
        $this->actingAs($this->sam)->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Portfolio')->has('projects', 0));

        $this->assertSame('No projects yet', $this->str('dash.empty.title'));
        $this->assertSame('Projects show up here after an agent posts its first update.', $this->str('dash.empty.body'));
    }

    #[Test]
    public function search_is_client_side_so_the_server_sends_every_project_and_the_copy_keys_exist(): void
    {
        $this->project($this->sam, 'Garden planner', ['status' => 'smooth', 'status_set_at' => now(), 'status_set_by' => 'agent-ava']);

        $this->actingAs($this->sam)->get('/?q=invoice')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Portfolio')->has('projects', 1)->where('projects.0.name', 'Garden planner'));

        foreach (['dash.noresults.title' => 'No projects match “{q}”', 'dash.noresults.clear' => 'Clear search', 'dash.search.placeholder' => 'Search projects and updates'] as $key => $text) {
            $this->assertSame($text, $this->str($key));
        }
    }

    #[Test]
    public function archived_projects_are_flagged_and_sent_with_the_rest(): void
    {
        $this->project($this->sam, 'Garden planner', ['status' => 'smooth', 'status_set_at' => now(), 'status_set_by' => 'agent-ava']);
        $this->project($this->sam, 'Old newsletter', ['status' => 'archived', 'archived_at' => now()->subWeeks(3), 'status_set_at' => now()->subWeeks(3), 'status_set_by' => 'agent-ava']);

        $this->actingAs($this->sam)->get('/?archived=1')->assertInertia(fn (Assert $p) => $p
            ->has('projects', 2)
            ->where('projects.0.name', 'Garden planner')->where('projects.0.archived', false)
            ->where('projects.1.name', 'Old newsletter')->where('projects.1.archived', true)->where('projects.1.pmStatus', 'archived'));
    }

    #[Test]
    public function the_bot_line_data_carries_the_setter_and_an_honest_source(): void
    {
        $api = $this->project($this->sam, 'Garden planner', ['status' => 'waiting', 'status_set_at' => now()->subHours(2), 'status_set_by' => 'agent-ava', 'status_note' => 'Two decisions waiting on you.']);
        $ui = $this->project($this->sam, 'Studio website', ['status' => 'smooth', 'status_set_at' => now()->subHour(), 'status_set_by' => 'agent-ava']);
        $unknown = $this->project($this->sam, 'Internal tools', ['status' => 'smooth', 'status_set_at' => now(), 'status_set_by' => 'agent-ava']);
        $none = $this->project($this->sam, 'Example project');
        $this->event($this->sam, $api, 'api');
        $this->event($this->sam, $ui, 'ui');

        $this->actingAs($this->sam)->get('/')->assertInertia(function (Assert $p) use ($api, $ui, $unknown, $none) {
            $by = collect($p->toArray()['props']['projects'])->keyBy('id');
            $this->assertSame('api', $by[$api->id]['pmStatusSource']);
            $this->assertSame('Ava', $by[$api->id]['pmStatusSetByName']);
            $this->assertSame('Two decisions waiting on you.', $by[$api->id]['pmStatusNote']);
            $this->assertSame('ui', $by[$ui->id]['pmStatusSource']);
            $this->assertNull($by[$unknown->id]['pmStatusSource']); // no event logged: the "via" part is omitted, never faked
            $this->assertNull($by[$none->id]['pmStatus']);
            $this->assertNull($by[$none->id]['pmStatusSource']);
            $this->assertNull($by[$none->id]['pmStatusSetAt']);
        });
    }

    #[Test]
    public function a_user_only_sees_their_own_workspace_projects(): void
    {
        $other = app(AccountService::class)->register('Lee Other', 'lee@example.test', 'garden-planner-28');
        $this->project($other, 'Other secret');
        $this->project($this->sam, 'Garden planner');

        $this->actingAs($this->sam)->get('/')->assertInertia(fn (Assert $p) => $p
            ->has('projects', 1)->where('projects.0.name', 'Garden planner'));
    }

    #[Test]
    public function polymarket_is_hidden_for_non_owners_and_visible_for_the_owner(): void
    {
        $owner = app(CurrentWorkspace::class)->default()->owner;

        $this->actingAs($this->sam)->get('/')->assertInertia(fn (Assert $p) => $p->where('features.polymarket', false));
        $this->get('/polymarket')->assertNotFound();

        $this->actingAs($owner)->get('/')->assertInertia(fn (Assert $p) => $p->where('features.polymarket', true));
        $this->get('/polymarket')->assertOk();
    }

    #[Test]
    public function the_waiting_column_is_labelled_waiting_on_you_by_default_and_custom_labels_win(): void
    {
        $this->assertSame('Waiting on you', BoardColumns::label('waiting_tilen'));
        $this->assertSame('Waiting on you', $this->str('portfolio.col.waiting'));
        $this->assertSame('Waiting on Sam', BoardColumns::labelFor('waiting_tilen', ['waiting_tilen' => 'Waiting on Sam']));

        $p = $this->project($this->sam, 'Garden planner', ['column_labels' => ['waiting_tilen' => 'Needs Sam']]);
        $this->actingAs($this->sam)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('projects.0.columnLabels.waiting_tilen', 'Needs Sam')->where('projects.0.id', $p->id)
            ->has('projects.0.ticketCounts.waiting_tilen')->has('projects.0.waitingTilenCount'));
    }

    private function str(string $key): string
    {
        preg_match("/'".preg_quote($key, '/')."': '((?:[^'\\\\]|\\\\.)*)'/u", file_get_contents(resource_path('js/lib/strings.ts')), $m);
        $this->assertNotEmpty($m, "strings.ts has no key $key");

        return str_replace("\\'", "'", $m[1]);
    }
}
