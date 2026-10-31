<?php

namespace Tests\Feature\MultiUser;

use App\Models\Agent;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use App\Models\TicketReadState;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\AccountService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\McpHelpers;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/**
 * B2 (stage QA): a brand-new workspace never shows the legacy owner's name. Display names, actor ids, labels and
 * messages come from the workspace's own people and agents; web writes are recorded as the signed-in user
 * ('user:<id>'), also for a member who is not the owner.
 */
class NewWorkspaceNamesTest extends TestCase
{
    use McpHelpers, RefreshDatabase, TicketHelpers;

    /**
     * The only places the legacy name may appear: compatibility KEY names kept in API / MCP / web payloads (and the
     * raw column names in the export), and the fixed column key waiting_tilen (as a key, a value or inside text).
     */
    private const ALLOWED_KEYS = [
        'waiting_on_tilen', 'tilen_input_at', 'tilen_input_ack_at', 'tilen_answered_ask', 'unread_for_tilen',
        'is_tilen_input', 'status_unseen_by_tilen', 'waitingTilenCount',
    ];

    private const COLUMN_KEY = 'waiting_tilen';

    private User $sam;

    private Workspace $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets(); // the legacy default workspace has its data too: none of it may leak
        Notification::fake();
        $this->sam = app(AccountService::class)->register('Sam Rivera', 'sam@example.test', 'acme-studio-planner-27', 'Acme Studio');
        $this->sam->markEmailAsVerified();
        $this->acme = Workspace::query()->where('owner_user_id', $this->sam->id)->firstOrFail();
        app(CurrentWorkspace::class)->runAs($this->acme, fn () => Agent::query()->create(['id' => 'agent-ava', 'name' => 'Ava Builder', 'role_label' => 'PM']));
    }

    private function web(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    /** Inertia page props of a full page load. */
    private function props(User $user, string $url): array
    {
        $page = $this->web($user)->get($url)->assertOk()->viewData('page');

        return ['component' => $page['component'], 'props' => $page['props']];
    }

    /** Fails on any key or string value naming the legacy owner, except the explicit allow-list. */
    private function assertNoLegacyName(mixed $data, string $where, string $path = ''): void
    {
        if (is_object($data)) {
            $data = (array) $data;
        }
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $key = (string) $key;
                if (! in_array($key, self::ALLOWED_KEYS, true) && $key !== self::COLUMN_KEY) {
                    $this->assertStringNotContainsStringIgnoringCase('tilen', $key, "$where: key $path.$key");
                }
                $this->assertNoLegacyName($value, $where, "$path.$key");
            }

            return;
        }
        if (is_string($data)) {
            $this->assertStringNotContainsStringIgnoringCase('tilen', str_replace(self::COLUMN_KEY, '', $data), "$where: value at $path = $data");
        }
    }

    #[Test]
    public function a_new_workspace_shows_its_own_names_everywhere(): void
    {
        // Sam sets up a project and works the board in the web UI.
        $this->web($this->sam)->postJson('/projects', ['name' => 'Garden planner', 'slug' => 'garden-planner', 'ticket_prefix' => 'GP'])->assertCreated();
        $key = $this->web($this->sam)->postJson('/projects/garden-planner/tickets', ['title' => 'Pick a palette'])->assertCreated()
            ->assertJsonPath('data.created_by', 'user:'.$this->sam->id)
            ->assertJsonPath('data.created_by_name', 'Sam Rivera')->json('data.key');
        $this->web($this->sam)->postJson("/projects/garden-planner/tickets/$key/comments", ['body' => 'Warm tones, please.'])->assertCreated()
            ->assertJsonPath('data.author_id', 'user:'.$this->sam->id)->assertJsonPath('data.author_name', 'Sam Rivera');
        $this->web($this->sam)->patchJson("/projects/garden-planner/tickets/$key", ['assignee' => 'owner'])->assertOk()
            ->assertJsonPath('data.assignee_id', 'user:'.$this->sam->id)->assertJsonPath('data.assignee_name', 'Sam Rivera');
        $this->web($this->sam)->postJson("/projects/garden-planner/tickets/$key/move", ['status' => 'waiting_tilen', 'ask' => 'Green or blue?', 'ask_options' => ['Green', 'Blue']])->assertOk()
            ->assertJsonPath('data.status_label', 'Waiting on Sam')->assertJsonPath('data.waiting_on_owner', true)->assertJsonPath('data.waiting_on_tilen', true);
        $this->web($this->sam)->postJson("/projects/garden-planner/tickets/$key/comments", ['input_choice' => 'Green', 'is_owner_input' => true])->assertCreated()
            ->assertJsonPath('data.is_owner_input', true);
        $this->web($this->sam)->patchJson('/projects/garden-planner/status', ['status' => 'waiting', 'note' => 'Needs a palette'])->assertOk();
        $this->web($this->sam)->postJson("/projects/garden-planner/tickets/$key/seen")->assertOk();
        $this->web($this->sam)->postJson('/projects/garden-planner/seen')->assertOk();

        $this->assertSame(['user:'.$this->sam->id], TicketEvent::acrossWorkspaces()->where('ticket_id', Ticket::acrossWorkspaces()->where('key', $key)->value('id'))->distinct()->pluck('actor_id')->all());
        $this->assertSame('user:'.$this->sam->id, TicketReadState::query()->where('ticket_id', Ticket::acrossWorkspaces()->where('key', $key)->value('id'))->value('user'));

        // An agent of the workspace answers over the API, so agent names show up too.
        $token = $this->scopedToken($this->sam, $this->acme, 'write');
        $projectId = $this->bearer($token, 'GET', '/api/projects')->assertOk()->json('data.0.id');
        Project::acrossWorkspaces()->whereKey($projectId)->update(['owner_agent_id' => 'agent-ava']); // Ava is its PM
        $this->travelMinutes(5);
        $this->bearer($token, 'POST', "/api/tickets/$key/comments", ['body' => 'Green it is.', 'actor' => 'agent-ava'])->assertCreated();
        $this->bearer($token, 'POST', "/api/tickets/$key/ack-input", ['actor' => 'agent-ava'])->assertOk();

        $api = [
            'GET /api/projects' => '/api/projects',
            'GET /api/projects/{id}' => "/api/projects/$projectId",
            'GET /api/projects/{id}/board' => "/api/projects/$projectId/board",
            'GET /api/projects/{id}/status' => "/api/projects/$projectId/status",
            'GET /api/projects/{id}/activity' => "/api/projects/$projectId/activity",
            'GET /api/board-columns' => '/api/board-columns',
            'GET /api/tickets' => '/api/tickets',
            'GET /api/tickets?unread_for_owner=1' => '/api/tickets?unread_for_owner=1',
            'GET /api/tickets/{key}' => "/api/tickets/$key",
            'GET /api/tickets/{key}/comments' => "/api/tickets/$key/comments",
            'GET /api/tickets/{key}/events' => "/api/tickets/$key/events",
            'GET /api/ticket-comments/feed' => '/api/ticket-comments/feed?author=all',
            'GET /api/agents' => '/api/agents',
        ];
        foreach ($api as $where => $uri) {
            $this->assertNoLegacyName($this->bearer($token, 'GET', $uri)->assertOk()->json(), $where);
        }
        $ticket = $this->bearer($token, 'GET', "/api/tickets/$key")->json('data');
        $this->assertSame('Sam Rivera', $ticket['created_by_name']);
        $this->assertSame(['Sam Rivera', 'Sam Rivera', 'Ava Builder'], array_column($ticket['comments'], 'author_name'));
        $this->assertSame($ticket['tilen_input_at'], $ticket['owner_input_at']);
        $this->assertNotNull($ticket['owner_input_at']);
        $this->assertSame('human', $this->bearer($token, 'GET', "/api/projects/$projectId")->json('data.status_source'));
        // The API's unread view is the workspace owner's: Sam opened the ticket before Ava answered.
        $this->assertSame([$key], array_column($this->bearer($token, 'GET', '/api/tickets?unread_for_me=1')->json('data'), 'key'));

        $mcp = ['list_projects' => [], 'get_board' => ['project' => 'garden-planner'], 'list_tickets' => [], 'get_ticket' => ['key' => $key],
            'list_agents' => [], 'list_labels' => []];
        foreach ($mcp as $tool => $args) {
            $this->assertNoLegacyName($this->tool($token, $tool, $args), "MCP $tool");
        }
        $this->assertNoLegacyName($this->mcp($token, 'tools/list')->assertOk()->json(), 'MCP tools/list');
        $this->assertSame('Sam Rivera', $this->tool($token, 'get_ticket', ['key' => $key])['data']['created_by_name']);

        $pages = ['/', '/projects/garden-planner', "/projects/garden-planner/tickets/$key", '/account', '/account/tokens', '/welcome', '/usage'];
        foreach ($pages as $url) {
            $this->assertNoLegacyName($this->props($this->sam, $url), "page $url");
        }
        $board = $this->props($this->sam, "/projects/garden-planner/tickets/$key")['props'];
        $this->assertSame([['id' => 'user:'.$this->sam->id, 'name' => 'Sam Rivera', 'role' => 'You', 'type' => 'human']], array_values(array_filter($board['people'], fn ($p) => $p['type'] === 'human')));
        $this->assertSame('Sam Rivera', $board['ticket']['created_by_name']);
        $this->assertSame('Waiting on Sam', $board['board']['columns'][3]['label']);
        $this->assertSame('user:'.$this->sam->id, $board['auth']['user']['actor_id']);

        $export = $this->web($this->sam)->get('/account/export')->assertOk()->json();
        $this->assertNoLegacyName($export, 'GET /account/export');
        $this->assertSame('user:'.$this->sam->id, $export['tickets'][0]['created_by']);
        $this->assertSame('Sam Rivera', $export['actor_names']['user:'.$this->sam->id]);
    }

    #[Test]
    public function a_member_editing_on_the_web_is_recorded_as_themselves(): void
    {
        $this->web($this->sam)->postJson('/projects', ['name' => 'Garden planner', 'slug' => 'garden-planner', 'ticket_prefix' => 'GP'])->assertCreated();
        $key = $this->web($this->sam)->postJson('/projects/garden-planner/tickets', ['title' => 'Pick a palette'])->assertCreated()->json('data.key');

        $jo = User::factory()->create(['name' => 'Jo Member', 'email' => 'jo@example.test']);
        $this->acme->users()->attach($jo->id, ['role' => 'member']);
        $me = 'user:'.$jo->id;

        $this->web($jo)->patchJson("/projects/garden-planner/tickets/$key", ['title' => 'Pick a palette (v2)'])->assertOk();
        $this->web($jo)->postJson("/projects/garden-planner/tickets/$key/comments", ['body' => 'On it.'])->assertCreated()
            ->assertJsonPath('data.author_id', $me)->assertJsonPath('data.author_name', 'Jo Member');
        $this->web($jo)->postJson("/projects/garden-planner/tickets/$key/move", ['status' => 'in_progress'])->assertOk();
        $this->web($jo)->postJson("/projects/garden-planner/tickets/$key/seen")->assertOk();
        $this->web($jo)->patchJson('/projects/garden-planner/status', ['status' => 'smooth'])->assertOk()
            ->assertJsonPath('data.event.set_by', $me)->assertJsonPath('data.event.set_by_name', 'Jo Member');

        $ticket = Ticket::acrossWorkspaces()->where('key', $key)->firstOrFail();
        $this->assertSame('user:'.$this->sam->id, $ticket->created_by);
        $this->assertSame([$me], TicketEvent::acrossWorkspaces()->where('ticket_id', $ticket->id)->where('type', '!=', 'created')->distinct()->pluck('actor_id')->all());
        $this->assertSame($me, TicketComment::acrossWorkspaces()->where('ticket_id', $ticket->id)->value('author_id'));
        $this->assertSame([$me], TicketReadState::query()->where('ticket_id', $ticket->id)->pluck('user')->all()); // Jo's own read state

        // Jo sees themselves as "You", Sam as the owner; the assignee list has both people.
        $people = $this->props($jo, '/projects/garden-planner')['props']['people'];
        $humans = array_values(array_filter($people, fn ($p) => $p['type'] === 'human'));
        $this->assertSame([
            ['id' => $me, 'name' => 'Jo Member', 'role' => 'You', 'type' => 'human'],
            ['id' => 'user:'.$this->sam->id, 'name' => 'Sam Rivera', 'role' => 'Owner', 'type' => 'human'],
        ], $humans);
        // The waiting column is still named after the owner, not the member.
        $this->assertSame('Waiting on Sam', $this->props($jo, '/projects/garden-planner')['props']['board']['columns'][3]['label']);
    }

    #[Test]
    public function a_person_of_another_workspace_cannot_be_assigned(): void
    {
        $this->web($this->sam)->postJson('/projects', ['name' => 'Garden planner', 'slug' => 'garden-planner', 'ticket_prefix' => 'GP'])->assertCreated();
        $key = $this->web($this->sam)->postJson('/projects/garden-planner/tickets', ['title' => 'Pick a palette'])->assertCreated()->json('data.key');
        $stranger = $this->workspaceOwner();

        $this->web($this->sam)->patchJson("/projects/garden-planner/tickets/$key", ['assignee' => 'user:'.$stranger->id])
            ->assertUnprocessable()->assertJsonValidationErrors('assignee');
        $this->web($this->sam)->patchJson("/projects/garden-planner/tickets/$key", ['assignee_type' => 'human', 'assignee_id' => 'user:'.$stranger->id])
            ->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
    }
}
