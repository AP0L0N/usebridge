<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Tickets\TicketService;
use App\Support\Workspaces\CurrentWorkspace;
use Database\Seeders\BridgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\McpHelpers;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/** Each MCP tool against the token's own workspace; writes are checked through the REST API. */
class McpToolsTest extends TestCase
{
    use McpHelpers, RefreshDatabase, TicketHelpers;

    private Workspace $workspace;

    private User $tilen;

    /** Per-agent token: its name is Janice's agent id, so writes act as Janice (Actor). */
    private string $janice;

    /** Shared write token (name is not an agent): writes need `actor`. */
    private string $shared;

    private string $read;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
        Notification::fake();
        $this->workspace = app(CurrentWorkspace::class)->default();
        $this->tilen = $this->workspace->owner;
        $this->janice = $this->scopedToken($this->tilen, $this->workspace, 'write', self::JANICE);
        $this->shared = $this->scopedToken($this->tilen, $this->workspace, 'write', 'grok-laptop');
        $this->read = $this->scopedToken($this->tilen, $this->workspace, 'read', 'reader');
    }

    #[Test]
    public function read_tools_return_the_workspaces_data(): void
    {
        $a = $this->tool($this->janice, 'create_ticket', ['project' => self::CA, 'title' => 'Draft the brief', 'labels' => ['copy'], 'status' => 'in_progress']);
        $b = $this->tool($this->janice, 'create_ticket', ['project' => self::CA, 'title' => 'Book the venue', 'assignee' => self::ANATOLY]);
        $this->tool($this->shared, 'create_ticket', ['project' => self::MW, 'title' => 'Merkwell pricing', 'actor' => self::ANATOLY]);

        $projects = $this->tool($this->read, 'list_projects')['data'];
        $this->assertEqualsCanonicalizing([self::CA, self::MW], array_column($projects, 'id'));
        $this->assertSame(self::JANICE, collect($projects)->firstWhere('id', self::CA)['pm_agent_id']);

        $board = $this->tool($this->read, 'get_board', ['project' => 'concierge-avant'])['data'];
        $this->assertSame(self::CA, $board['project']['id']);
        $columns = collect($board['columns'])->keyBy('key');
        $this->assertSame(['Draft the brief'], array_column($columns['in_progress']['tickets'], 'title'));
        $this->assertSame(['Book the venue'], array_column($columns['todo']['tickets'], 'title'));
        $this->assertSame($this->bearer($this->read, 'GET', '/api/projects/'.self::CA.'/board')->json('data'), $board);

        $keys = fn (array $args) => array_column($this->tool($this->read, 'list_tickets', $args)['data'], 'key');
        $this->assertCount(3, $keys([]));
        $this->assertEqualsCanonicalizing([$a['data']['key'], $b['data']['key']], $keys(['project' => self::CA]));
        $this->assertSame([$b['data']['key']], $keys(['project' => 'concierge-avant', 'status' => 'todo']));
        $this->assertSame([$a['data']['key']], $keys(['label' => 'COPY']));
        $this->assertSame([$b['data']['key']], $keys(['assignee' => self::ANATOLY]));
        $this->assertSame([$b['data']['key']], $keys(['q' => 'venue']));
        $this->assertSame([$a['data']['key']], $keys(['q' => $a['data']['key']]));
        $this->assertCount(1, $keys(['limit' => 1]));
        $this->assertStringContainsString('status must be one of', $this->toolError($this->read, 'list_tickets', ['status' => 'nope']));

        $ticket = $this->tool($this->read, 'get_ticket', ['key' => strtolower($a['data']['key'])])['data'];
        $this->assertSame('Draft the brief', $ticket['title']);
        $this->assertArrayHasKey('comments', $ticket);
        $this->assertSame('created', end($ticket['events'])['type']);

        $agents = $this->tool($this->read, 'list_agents')['data'];
        $this->assertContains(['id' => self::JANICE, 'name' => 'Dr. Janice Polito', 'role_label' => 'Sales / content owner'], $agents);

        $labels = $this->tool($this->read, 'list_labels')['data'];
        $this->assertSame(1, collect($labels)->firstWhere('name', 'copy')['ticket_count']);
    }

    #[Test]
    public function write_tools_work_and_show_up_on_the_rest_api(): void
    {
        $created = $this->tool($this->janice, 'create_ticket', [
            'project' => 'concierge-avant', 'title' => 'Draft the brief', 'description' => 'Two pages', 'priority' => 'p1', 'labels' => ['copy'],
        ]);
        $key = $created['data']['key'];
        $this->assertSame([], $created['warnings']);
        $rest = fn () => $this->bearer($this->read, 'GET', "/api/tickets/$key")->assertOk()->json('data');
        $this->assertSame(['Draft the brief', 'p1', self::JANICE, 'agent'], [$rest()['title'], $rest()['priority'], $rest()['created_by'], $rest()['created_by_type']]);

        $this->tool($this->janice, 'update_ticket', ['key' => $key, 'title' => 'Draft the short brief', 'assignee' => BridgeSeeder::AGENT_TOMMY]);
        $this->assertSame(['Draft the short brief', 'Two pages', BridgeSeeder::AGENT_TOMMY], [$rest()['title'], $rest()['description'], $rest()['assignee_id']]);

        $this->assertStringContainsString(TicketService::ASK_REQUIRED, ($this->toolError($this->janice, 'move_ticket', ['key' => $key, 'status' => 'waiting_tilen'])));
        $this->tool($this->janice, 'move_ticket', ['key' => $key, 'status' => 'waiting_tilen', 'ask' => 'Approve the copy?', 'ask_options' => ['Yes', 'No']]);
        $this->assertSame(['waiting_tilen', 'Approve the copy?', ['Yes', 'No']], [$rest()['status'], $rest()['ask'], $rest()['ask_options']]);

        $comment = $this->tool($this->janice, 'comment_ticket', ['key' => $key, 'body' => 'First draft attached.'])['data'];
        $this->assertSame([self::JANICE, 'First draft attached.'], [$comment['author_id'], $comment['body']]);
        $comments = $this->bearer($this->read, 'GET', "/api/tickets/$key/comments")->json('data');
        $this->assertSame([[self::JANICE, 'First draft attached.']], array_map(fn ($c) => [$c['author_id'], $c['body']], $comments));
        $types = array_column($this->bearer($this->read, 'GET', "/api/tickets/$key/events")->json('data'), 'type');
        $this->assertSame([], array_diff(['created', 'edited', 'assigned', 'ask_set', 'moved', 'commented'], $types));
        $this->assertSame([self::JANICE], array_values(array_unique(array_column($this->bearer($this->read, 'GET', "/api/tickets/$key/events")->json('data'), 'actor_id'))));

        $status = $this->tool($this->janice, 'post_status', ['project' => self::CA, 'body' => 'Brief is with Tilen.', 'inputs' => [['text' => 'Venue budget', 'waiting_on' => 'Tilen']]]);
        $this->assertSame([self::JANICE, 'Brief is with Tilen.'], [$status['data']['agent_id'], $status['data']['body']]);
        $this->assertCount(1, $status['inputs']);
        $updates = $this->bearer($this->read, 'GET', '/api/status-updates?project_id='.self::CA)->json('data');
        $this->assertSame('Brief is with Tilen.', $updates[0]['body']);
        $this->assertSame(self::JANICE, $updates[0]['agent_id']);

        $set = $this->tool($this->janice, 'set_project_status', ['project' => self::CA, 'status' => 'waiting', 'note' => 'Needs the budget'])['data'];
        $this->assertSame(['waiting', 'Needs the budget', self::JANICE], [$set['current']['status'], $set['current']['note'], $set['current']['set_by']]);
        $this->bearer($this->read, 'GET', '/api/projects/'.self::CA.'/status')
            ->assertJsonPath('data.current.status', 'waiting')->assertJsonPath('data.current.set_by', self::JANICE);
        // Same authorization as PATCH /api/projects/{id}/status: Janice is not Merkwell's PM, only Captain archives.
        $this->assertStringContainsString("Only the project's PM", $this->toolError($this->janice, 'set_project_status', ['project' => self::MW, 'status' => 'waiting']));
        $this->assertStringContainsString('Only Captain', $this->toolError($this->janice, 'set_project_status', ['project' => self::CA, 'status' => 'archived']));
    }

    #[Test]
    public function the_actor_is_resolved_like_on_the_rest_api(): void
    {
        // Shared token: actor required, must be an agent of this workspace, never tilen.
        $this->assertStringContainsString('actor field is required', $this->toolError($this->shared, 'create_ticket', ['project' => self::CA, 'title' => 'x']));
        $this->assertStringContainsString('Unknown actor', $this->toolError($this->shared, 'create_ticket', ['project' => self::CA, 'title' => 'x', 'actor' => 'agent-nobody']));
        $key = $this->tool($this->shared, 'create_ticket', ['project' => self::CA, 'title' => 'By Captain', 'actor' => self::CAPTAIN])['data']['key'];
        $this->assertStringContainsString("Tilen's input only comes from the Bridge UI", $this->toolError($this->shared, 'comment_ticket', ['key' => $key, 'body' => 'x', 'actor' => 'tilen']));
        $this->bearer($this->read, 'GET', "/api/tickets/$key")->assertJsonPath('data.created_by', self::CAPTAIN);

        // Per-agent token: acts as its agent; a different actor is refused.
        $this->assertStringContainsString('This token belongs to '.self::JANICE, $this->toolError($this->janice, 'comment_ticket', ['key' => $key, 'body' => 'x', 'actor' => self::CAPTAIN]));
        $this->tool($this->janice, 'comment_ticket', ['key' => $key, 'body' => 'Mine', 'actor' => self::JANICE]);
        $this->bearer($this->read, 'GET', "/api/tickets/$key/comments")->assertJsonPath('data.0.author_id', self::JANICE);

        // Validation errors are tool errors, not data.
        $this->assertStringContainsString('title', $this->toolError($this->janice, 'create_ticket', ['project' => self::CA]));
        $this->assertSame("Unknown ticket 'CA-999'.", $this->toolError($this->janice, 'get_ticket', ['key' => 'CA-999']));
        $this->assertSame("Unknown project 'nope'.", $this->toolError($this->janice, 'get_board', ['project' => 'nope']));
    }
}
