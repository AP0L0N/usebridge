<?php

namespace Tests\Feature;

use App\Http\Controllers\SearchController;
use App\Models\Agent;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/**
 * Global search (GET /search, Cmd/Ctrl+K): tickets by key or title, projects and workstreams by name, grouped; an exact
 * key first in any case; archived items only with ?archived=1 ("Show archived"); the signed-in user's workspace only.
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase, TicketHelpers;

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

    private function search(string $q, bool $archived = false): TestResponse
    {
        return $this->asOwner()->getJson('/search?'.http_build_query(['q' => $q] + ($archived ? ['archived' => 1] : [])));
    }

    /** @return list<string> */
    private function keys(TestResponse $response): array
    {
        return array_column($response->json('data.tickets'), 'key');
    }

    #[Test]
    public function an_exact_key_ranks_first_in_any_case(): void
    {
        foreach (range(1, 12) as $n) {
            $this->api()->newTicket(['title' => "Task $n"]);
            $this->travelMinutes(1);
        }
        $this->assertSame('BH-1', Ticket::query()->orderBy('created_at')->value('key'));

        foreach (['BH-1', 'bh-1', ' Bh-1 '] as $q) {
            $keys = $this->keys($this->search($q)->assertOk());
            $this->assertSame('BH-1', $keys[0], "exact key first for '$q'");
            $this->assertEqualsCanonicalizing(['BH-1', 'BH-10', 'BH-11', 'BH-12'], $keys);
        }
        $this->search('bh-12')->assertJsonPath('data.tickets.0.key', 'BH-12')
            ->assertJsonPath('data.tickets.0.project_slug', 'blue-harbor')->assertJsonPath('data.tickets.0.project_name', 'Blue Harbor');
    }

    #[Test]
    public function results_are_grouped_by_type_and_capped_per_group(): void
    {
        $ticket = $this->api()->newTicket(['title' => 'Harbor signage']);
        $ws = $this->asOwner()->postJson('/projects/northwind/workstreams', ['name' => 'Harbor crossing'])->assertCreated()->json('data.id');

        $this->search('harbor')->assertOk()->assertExactJson(['data' => [
            'tickets' => [['id' => $ticket['id'], 'key' => $ticket['key'], 'title' => 'Harbor signage', 'project_slug' => 'blue-harbor', 'project_name' => 'Blue Harbor', 'archived' => false]],
            'projects' => [['id' => self::BH, 'slug' => 'blue-harbor', 'name' => 'Blue Harbor', 'archived' => false]],
            'workstreams' => [['id' => $ws, 'name' => 'Harbor crossing', 'project_slug' => 'northwind', 'project_name' => 'Northwind', 'archived' => false]],
        ]]);

        foreach (range(1, SearchController::LIMIT + 2) as $n) {
            $this->api()->newTicket(['title' => "Signage $n"]);
        }
        $this->search('signage')->assertOk()->assertJsonCount(SearchController::LIMIT, 'data.tickets');
        $this->search('   ')->assertOk()->assertExactJson(['data' => ['tickets' => [], 'projects' => [], 'workstreams' => []]]);
        $this->search(str_repeat('x', SearchController::QUERY_MAX + 1))->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    #[Test]
    public function archived_items_show_only_with_show_archived(): void
    {
        $archivedTicket = $this->api()->newTicket(['title' => 'Old brochure']);
        $liveTicket = $this->api()->newTicket(['title' => 'New brochure']);
        $nwTicket = $this->api()->newTicket(['title' => 'Northwind brochure'], self::NW);
        $this->asOwner()->postJson("/projects/blue-harbor/tickets/{$archivedTicket['key']}/archive")->assertOk();
        $this->asOwner()->postJson('/projects/northwind/archive')->assertOk();

        // Off: an archived ticket, an archived project, and that project's workstreams and tickets are hidden.
        $this->assertSame([$liveTicket['key']], $this->keys($this->search('brochure')));
        $this->search('northwind')->assertJsonCount(0, 'data.projects');
        $this->search('general')->assertJsonCount(1, 'data.workstreams')->assertJsonPath('data.workstreams.0.project_slug', 'blue-harbor');

        // On: all of them, flagged.
        $all = $this->search('brochure', true);
        $this->assertEqualsCanonicalizing([$archivedTicket['key'], $liveTicket['key'], $nwTicket['key']], $this->keys($all));
        $flags = array_column($all->json('data.tickets'), 'archived', 'key');
        $this->assertSame([$archivedTicket['key'] => true, $liveTicket['key'] => false, $nwTicket['key'] => true], array_intersect_key($flags, array_flip([$archivedTicket['key'], $liveTicket['key'], $nwTicket['key']])));
        $this->search('northwind', true)->assertJsonPath('data.projects.0.archived', true);
        $this->search('general', true)->assertJsonCount(2, 'data.workstreams')
            ->assertJsonPath('data.workstreams.1.project_slug', 'northwind')->assertJsonPath('data.workstreams.1.archived', true);
    }

    #[Test]
    public function search_stays_inside_the_signed_in_users_workspace(): void
    {
        $this->api()->newTicket(['title' => 'Harbor secret']);
        $owner = User::factory()->create(['email' => 'grok@example.test']);
        $other = Workspace::query()->create(['slug' => 'grok', 'name' => 'Grok', 'owner_user_id' => $owner->id]);
        app(CurrentWorkspace::class)->runAs($other, function () {
            Agent::query()->create(['id' => 'agent-grok', 'name' => 'Grok', 'role_label' => 'Bot']);
            Project::query()->create(['id' => 'proj-grok', 'name' => 'Harbor Labs', 'slug' => 'grok', 'owner_agent_id' => 'agent-grok']);
            Ticket::query()->create(['id' => 'tk-grok', 'project_id' => 'proj-grok', 'key' => 'GR-1', 'title' => 'Harbor plans', 'status' => 'todo', 'created_by' => 'agent-grok', 'created_by_type' => 'agent']);
        });

        $mine = $this->search('harbor')->assertOk();
        $this->assertSame(['Blue Harbor'], array_column($mine->json('data.projects'), 'name'));
        $this->assertSame(['Harbor secret'], array_column($mine->json('data.tickets'), 'title'));
        $this->search('GR-1', true)->assertJsonCount(0, 'data.tickets');

        $theirs = $this->actingAs($owner, 'web')->getJson('/search?q=harbor&archived=1')->assertOk();
        $this->assertSame(['Harbor Labs'], array_column($theirs->json('data.projects'), 'name'));
        $this->assertSame(['GR-1'], array_column($theirs->json('data.tickets'), 'key'));
        $this->getJson('/search?q=BH-1&archived=1')->assertOk()->assertJsonCount(0, 'data.tickets');
    }

    #[Test]
    public function guests_are_sent_to_login(): void
    {
        $this->get('/search?q=BH-1')->assertRedirect('/login');
    }
}
