<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use App\Support\TicketPrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

class TicketsApiTest extends TestCase
{
    use RefreshDatabase;
    use TicketHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function every_tickets_endpoint_needs_the_token(): void
    {
        foreach ([
            ['get', '/api/board-columns'], ['get', '/api/tickets'], ['post', '/api/tickets'], ['get', '/api/tickets/BH-1'],
            ['patch', '/api/tickets/BH-1'], ['post', '/api/tickets/BH-1/move'], ['post', '/api/tickets/BH-1/ack-input'],
            ['get', '/api/projects/'.self::BH.'/board'], ['post', '/api/projects/'.self::BH.'/tickets'],
            ['patch', '/api/projects/'.self::BH.'/status'], ['get', '/api/ticket-comments/feed'], ['get', '/api/labels'],
            ['post', '/api/comments/1/retract'], ['get', '/api/projects/'.self::BH.'/activity'],
        ] as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized();
        }
    }

    #[Test]
    public function board_columns_are_the_fixed_six(): void
    {
        $this->api()->getJson('/api/board-columns')->assertOk()->assertExactJson(['data' => [
            ['key' => 'backlog', 'label' => 'Backlog', 'order' => 0],
            ['key' => 'todo', 'label' => 'To do', 'order' => 1],
            ['key' => 'in_progress', 'label' => 'In progress', 'order' => 2],
            ['key' => 'waiting_tilen', 'label' => 'Waiting on Tilen', 'order' => 3],
            ['key' => 'review', 'label' => 'Review / QA', 'order' => 4],
            ['key' => 'done', 'label' => 'Done', 'order' => 5],
        ]]);
    }

    #[Test]
    public function prefixes_come_from_the_name_and_are_unique(): void
    {
        $this->assertSame('BH', Project::find(self::BH)->ticket_prefix);
        $this->assertSame('NW', Project::find(self::NW)->ticket_prefix); // fixed by the roster file's ticket_prefixes
        $this->assertSame('BH', TicketPrefix::generate('Blue Harbor'));
        $this->assertSame('BHA', TicketPrefix::generate('Blue Harbor', ['BH']));
        $this->assertSame('NO', TicketPrefix::generate('northwind'));
        $this->assertSame('NOR', TicketPrefix::generate('Northwind', ['NO']));
        $this->assertSame('ZA', TicketPrefix::generate('Žabja Akademija'));
        $this->assertSame('P', TicketPrefix::generate('123'));
        $this->assertSame('X2', TicketPrefix::generate('X', ['X']));
        // The roster's fixed prefixes win for those ids; the reserved prefixes are never generated for other projects.
        $this->assertSame('BH', TicketPrefix::forProject('proj-blue-harbor', 'Harbor'));
        $this->assertSame('NW', TicketPrefix::forProject('proj-northwind', 'Northwind'));
        $this->assertSame('HA', TicketPrefix::forProject('proj-other', 'Harbor'));
        $this->assertSame('BHA', TicketPrefix::forProject('proj-other', 'Blue Harbor'));

        // Created via the API: same generator, optional explicit prefix.
        $this->api()->postJson('/api/projects', ['name' => 'Bay Harbor', 'slug' => 'bay-harbor', 'owner_agent_id' => self::JANICE])
            ->assertCreated()->assertJsonPath('data.ticket_prefix', 'BHA');
        $this->postJson('/api/projects', ['name' => 'Northwind Two', 'slug' => 'mw2', 'owner_agent_id' => self::ANATOLY, 'ticket_prefix' => 'MV'])
            ->assertCreated()->assertJsonPath('data.ticket_prefix', 'MV');
        $this->postJson('/api/projects', ['name' => 'X', 'slug' => 'x', 'owner_agent_id' => self::ANATOLY, 'ticket_prefix' => 'NW'])
            ->assertUnprocessable()->assertJsonValidationErrors('ticket_prefix');
        // The prefix may change only before the first ticket.
        $this->patchJson('/api/projects/'.self::NW, ['ticket_prefix' => 'MK'])->assertOk()->assertJsonPath('data.ticket_prefix', 'MK');
        $this->newTicket([], self::NW);
        $this->patchJson('/api/projects/'.self::NW, ['ticket_prefix' => 'MX'])->assertUnprocessable();
    }

    #[Test]
    public function create_assigns_keys_from_the_sequence_and_never_reuses_them(): void
    {
        $this->api();
        $one = $this->newTicket();
        $this->assertSame('BH-1', $one['key']);
        $this->assertStringStartsWith('tk-', $one['id']);
        $this->assertSame('todo', $one['status']);
        $this->assertSame('p3', $one['priority']);
        $this->assertSame(self::JANICE, $one['created_by']);
        $this->assertSame('agent', $one['created_by_type']);
        $this->assertSame(self::JANICE, $one['pm_agent_id']);
        $this->assertSame('https://bridge.example.test/projects/blue-harbor/tickets/BH-1', $one['url']);

        $two = $this->newTicket();
        $this->postJson("/api/tickets/{$two['key']}/archive", ['actor' => self::JANICE])->assertOk()->assertJsonPath('data.archived', true);
        $this->assertSame('BH-3', $this->newTicket()['key']);
        $this->assertSame('NW-1', $this->newTicket([], self::NW)['key']);
        $this->assertSame(3, Project::find(self::BH)->ticket_seq);

        // POST /api/tickets with project_id is the same endpoint.
        $this->postJson('/api/tickets', ['project_id' => self::BH, 'title' => 'Via flat route', 'actor' => self::JANICE])
            ->assertCreated()->assertJsonPath('data.key', 'BH-4');
        $this->postJson('/api/tickets', ['project_id' => 'proj-nope', 'title' => 'x', 'actor' => self::JANICE])->assertNotFound();
        $this->postJson('/api/tickets', ['title' => 'x', 'actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('project_id');
    }

    #[Test]
    public function create_with_every_field(): void
    {
        $t = $this->api()->postJson('/api/projects/'.self::BH.'/tickets', [
            'title' => 'Approve staging', 'description' => "# Staging\nLink", 'status' => 'waiting_tilen',
            'assignee' => self::JANICE, 'priority' => 'p1', 'due_on' => '2026-11-02', 'blocked_reason' => 'Client copy',
            'ask' => 'Approve staging?', 'ask_context' => 'Visual QA passed.', 'ask_options' => ['Approve', 'Hold'],
            'labels' => ['M2 launch', 'design'], 'actor' => self::JANICE,
        ])->assertCreated()->json('data');

        $this->assertSame(['agent', self::JANICE, 'Dr. Janice Polito'], [$t['assignee_type'], $t['assignee_id'], $t['assignee_name']]);
        $this->assertSame(['p1', '2026-11-02', true, 'Client copy'], [$t['priority'], $t['due_on'], $t['blocked'], $t['blocked_reason']]);
        $this->assertSame(['Approve staging?', 'Visual QA passed.', ['Approve', 'Hold']], [$t['ask'], $t['ask_context'], $t['ask_options']]);
        $this->assertTrue($t['waiting_on_tilen']);
        $this->assertSame(Carbon::now()->toIso8601String(), $t['waiting_since']);
        $this->assertSame([0, 1], [$t['waiting_age_days'], $t['waiting_level']]);
        $this->assertSame(['M2 launch', 'design'], array_column($t['labels'], 'name'));
        $this->assertSame(['grey', 'grey'], array_column($t['labels'], 'color'));
        $this->assertSame('created', TicketEvent::query()->where('ticket_id', $t['id'])->value('type'));
        $this->assertNotNull(Project::find(self::BH)->last_activity_at);
    }

    #[Test]
    public function create_validation_and_rules(): void
    {
        $this->api();
        $p = '/api/projects/'.self::BH.'/tickets';
        $this->postJson($p, ['actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson($p, ['title' => str_repeat('x', 141), 'actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson($p, ['title' => 'x', 'status' => 'blocked', 'actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson($p, ['title' => 'x', 'status' => 'waiting_tilen', 'actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('ask');
        $this->postJson($p, ['title' => 'x', 'ask_options' => ['a', 'b', 'c', 'd', 'e'], 'actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('ask_options');
        $this->postJson($p, ['title' => 'x', 'ask_options' => [str_repeat('o', 61)], 'actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('ask_options.0');
        $this->postJson($p, ['title' => 'x', 'priority' => 'p0', 'actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('priority');
        $this->postJson($p, ['title' => 'x', 'due_on' => '02.11.2026', 'actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('due_on');
        $this->postJson($p, ['title' => 'x', 'labels' => [str_repeat('l', 25)], 'actor' => self::JANICE])->assertUnprocessable();
        $this->postJson($p, ['title' => 'x', 'assignee_type' => 'agent', 'assignee_id' => 'agent-nobody', 'actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
        $this->postJson('/api/projects/proj-nope/tickets', ['title' => 'x', 'actor' => self::JANICE])->assertNotFound()->assertJsonStructure(['message']);
        $this->assertSame(0, Ticket::count());

        // Archived project: 409.
        Project::find(self::BH)->update(['archived_at' => now()]);
        $this->postJson($p, ['title' => 'x', 'actor' => self::JANICE])->assertStatus(409)->assertJsonStructure(['message']);
    }

    #[Test]
    public function actor_rules(): void
    {
        $this->api();
        $p = '/api/projects/'.self::BH.'/tickets';
        $this->postJson($p, ['title' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('actor');
        $this->postJson($p, ['title' => 'x', 'actor' => 'agent-ghost'])->assertUnprocessable()->assertJsonValidationErrors('actor');
        $this->postJson($p, ['title' => 'x', 'actor' => 'tilen'])->assertUnprocessable()
            ->assertJsonPath('errors.actor.0', fn ($m) => str_contains($m, "Tilen's input only comes from the Bridge UI"));
        $this->postJson($p, ['title' => 'x', 'actor' => 'Tilen'])->assertUnprocessable()->assertJsonValidationErrors('actor');
        $this->assertSame(0, Ticket::count());
    }

    #[Test]
    public function a_token_named_after_an_agent_is_the_actor(): void
    {
        $plain = $this->workspaceUser()->createToken(self::JANICE)->plainTextToken;
        $h = ['Authorization' => 'Bearer '.$plain];

        $t = $this->withHeaders($h)->postJson('/api/projects/'.self::BH.'/tickets', ['title' => 'No actor needed'])
            ->assertCreated()->json('data');
        $this->assertSame(self::JANICE, $t['created_by']);
        $this->withHeaders($h)->postJson('/api/projects/'.self::BH.'/tickets', ['title' => 'Same actor', 'actor' => self::JANICE])->assertCreated();
        $this->withHeaders($h)->postJson('/api/projects/'.self::BH.'/tickets', ['title' => 'x', 'actor' => self::ANATOLY])->assertForbidden();
        $this->withHeaders($h)->postJson("/api/tickets/{$t['key']}/comments", ['body' => 'hi', 'actor' => self::CAPTAIN])->assertForbidden();
        $this->withHeaders($h)->postJson('/api/projects/'.self::BH.'/tickets', ['title' => 'x', 'actor' => 'tilen'])->assertUnprocessable();

        // A token whose name is not an agent (the shared CEO token) still needs the body actor.
        $ceo = $this->workspaceUser()->createToken('ceo', ['ceo'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$ceo])->postJson('/api/projects/'.self::BH.'/tickets', ['title' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('actor');
    }

    #[Test]
    public function list_and_filters(): void
    {
        $this->api();
        $a = $this->newTicket(['title' => 'A', 'assignee' => self::JANICE, 'labels' => ['design']]);
        $b = $this->newTicket(['title' => 'B', 'status' => 'in_progress', 'blocked_reason' => 'API down']);
        $c = $this->waitingTicket(['title' => 'C', 'assignee' => 'tilen']);
        $d = $this->newTicket(['title' => 'D'], self::NW);
        $e = $this->newTicket(['title' => 'E', 'status' => 'done']);
        $this->postJson("/api/tickets/{$e['key']}/archive", ['actor' => self::JANICE])->assertOk();

        $keys = fn (string $q) => array_column($this->getJson('/api/tickets'.$q)->assertOk()->json('data'), 'key');
        $this->assertEqualsCanonicalizing(['BH-1', 'BH-2', 'BH-3', 'NW-1'], $keys(''));
        $this->assertSame(['BH-1', 'BH-2', 'BH-3'], $keys('?project_id='.self::BH));
        $this->assertSame(['BH-1', 'BH-2', 'BH-3'], array_column($this->getJson('/api/projects/'.self::BH.'/tickets')->json('data'), 'key'));
        $this->assertSame(['BH-2'], $keys('?status=in_progress'));
        $this->assertSame(['BH-2', 'BH-3'], $keys('?status=in_progress,waiting_tilen&project_id='.self::BH));
        $this->assertSame(['BH-1'], $keys('?assignee='.self::JANICE));
        $this->assertSame(['BH-3'], $keys('?assignee=tilen'));
        $this->assertSame(['BH-1'], $keys('?label=Design'));
        $this->assertSame(['BH-2'], $keys('?blocked=1'));
        $this->assertSame([], $keys('?needs_pm_pickup=1'));
        $this->assertContains('BH-4', $keys('?include_archived=1'));
        $this->assertCount(2, $keys('?limit=2'));
        $this->getJson('/api/tickets?status=nope')->assertUnprocessable();
        $this->getJson('/api/tickets?limit=201')->assertUnprocessable();

        $this->travelMinutes(60);
        $this->patchJson("/api/tickets/{$d['key']}", ['priority' => 'p2', 'actor' => self::ANATOLY])->assertOk();
        $this->assertSame(['NW-1'], $keys('?updated_since='.urlencode(Carbon::now()->subMinute()->toIso8601String())));
        $this->assertNotEmpty([$a, $b, $c]);
    }

    #[Test]
    public function show_by_key_or_id_with_comments_and_events(): void
    {
        $this->api();
        $t = $this->newTicket();
        $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => 'First', 'actor' => self::JANICE])->assertCreated();
        $byKey = $this->getJson('/api/tickets/bh-1')->assertOk()->json('data');
        $byId = $this->getJson("/api/tickets/{$t['id']}")->assertOk()->json('data');
        $this->assertSame($byKey['id'], $byId['id']);
        $this->assertSame('First', $byKey['comments'][0]['body']);
        $this->assertSame(['commented', 'created'], array_column($byKey['events'], 'type'));
        $this->assertSame(1, $byKey['comment_count']);
        $this->getJson('/api/tickets/BH-99')->assertNotFound()->assertJsonStructure(['message']);
        $this->getJson('/api/tickets/tk-nope')->assertNotFound();
    }

    #[Test]
    public function patch_edits_fields_and_logs_events(): void
    {
        $this->api();
        $t = $this->newTicket(['labels' => ['design', 'copy']]);
        $r = $this->patchJson("/api/tickets/{$t['key']}", [
            'title' => 'Draft the final brief', 'description' => 'Body', 'assignee' => 'Ana from the client',
            'priority' => 'p2', 'due_on' => '2026-11-05', 'blocked_reason' => 'Waiting for client copy',
            'labels' => ['Copy', 'legal'], 'actor' => self::JANICE,
        ])->assertOk();
        $d = $r->json('data');
        $this->assertSame(['Draft the final brief', 'Body', 'human', 'Ana from the client'], [$d['title'], $d['description'], $d['assignee_type'], $d['assignee_id']]);
        $this->assertSame(['copy', 'legal'], array_column($d['labels'], 'name')); // existing 'copy' reused case-insensitively
        $this->assertSame(3, Label::count());
        $this->assertSame([], $r->json('warnings'));

        $types = fn () => TicketEvent::query()->where('ticket_id', $t['id'])->orderBy('id')->pluck('type')->all();
        $this->assertSame(['created', 'edited', 'edited', 'assigned', 'priority', 'due', 'blocked', 'labelled'], $types());

        $this->patchJson("/api/tickets/{$t['key']}", ['blocked_reason' => null, 'assignee' => 'tilen', 'actor' => self::JANICE])
            ->assertOk()->assertJsonPath('data.assignee_id', 'tilen')->assertJsonPath('data.assignee_name', 'Tilen')->assertJsonPath('data.blocked', false);
        $this->patchJson("/api/tickets/{$t['key']}", ['assignee_type' => 'agent', 'assignee_id' => self::ANATOLY, 'actor' => self::JANICE])
            ->assertOk()->assertJsonPath('data.assignee_type', 'agent');
        $this->patchJson("/api/tickets/{$t['key']}", ['assignee' => null, 'labels' => [], 'actor' => self::JANICE])
            ->assertOk()->assertJsonPath('data.assignee_id', null)->assertJsonPath('data.labels', []);
        $this->assertSame(['assigned', 'unblocked', 'assigned', 'assigned', 'labelled'], array_slice($types(), 8));

        // Unchanged values log nothing; status in PATCH behaves like move; ask can't be cleared while waiting.
        $count = TicketEvent::count();
        $this->patchJson("/api/tickets/{$t['key']}", ['priority' => 'p2', 'actor' => self::JANICE])->assertOk();
        $this->assertSame($count, TicketEvent::count());
        $this->patchJson("/api/tickets/{$t['key']}", ['status' => 'waiting_tilen', 'actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('ask');
        $this->patchJson("/api/tickets/{$t['key']}", ['status' => 'waiting_tilen', 'ask' => 'Which brief?', 'actor' => self::JANICE])
            ->assertOk()->assertJsonPath('data.status', 'waiting_tilen')->assertJsonPath('data.ask', 'Which brief?');
        $this->patchJson("/api/tickets/{$t['key']}", ['ask' => null, 'actor' => self::JANICE])->assertUnprocessable();
        $this->assertSame('Which brief?', Ticket::first()->ask);
    }

    #[Test]
    public function move_places_tickets_densely(): void
    {
        $this->api();
        foreach (range(1, 4) as $i) {
            $this->newTicket(['title' => "T$i"]);
        }
        $order = fn (string $status = 'todo') => Ticket::query()->where('status', $status)->whereNull('archived_at')->orderBy('position')->pluck('key')->all();
        $positions = fn (string $status = 'todo') => Ticket::query()->where('status', $status)->whereNull('archived_at')->orderBy('position')->pluck('position')->all();
        $this->assertSame(['BH-1', 'BH-2', 'BH-3', 'BH-4'], $order());

        $this->postJson('/api/tickets/BH-4/move', ['status' => 'todo', 'before_id' => 'BH-1', 'actor' => self::JANICE])->assertOk()->assertJsonPath('data.position', 0);
        $this->assertSame(['BH-4', 'BH-1', 'BH-2', 'BH-3'], $order());
        $this->postJson('/api/tickets/BH-4/move', ['status' => 'todo', 'after_id' => 'BH-2', 'actor' => self::JANICE])->assertOk();
        $this->assertSame(['BH-1', 'BH-2', 'BH-4', 'BH-3'], $order());
        $this->postJson('/api/tickets/BH-3/move', ['status' => 'todo', 'position' => 0, 'actor' => self::JANICE])->assertOk();
        $this->assertSame(['BH-3', 'BH-1', 'BH-2', 'BH-4'], $order());
        $this->postJson('/api/tickets/BH-3/move', ['status' => 'todo', 'position' => 99, 'actor' => self::JANICE])->assertOk()->assertJsonPath('data.position', 3);

        // Across columns: default end of target column; source renumbered.
        $this->postJson('/api/tickets/BH-1/move', ['status' => 'in_progress', 'actor' => self::JANICE])->assertOk();
        $this->postJson('/api/tickets/BH-2/move', ['status' => 'in_progress', 'actor' => self::JANICE])->assertOk()->assertJsonPath('data.position', 1);
        $this->postJson('/api/tickets/BH-4/move', ['status' => 'in_progress', 'before_id' => 'BH-2', 'actor' => self::JANICE])->assertOk();
        $this->assertSame(['BH-1', 'BH-4', 'BH-2'], $order('in_progress'));
        $this->assertSame([0, 1, 2], $positions('in_progress'));
        $this->assertSame(['BH-3'], $order());
        $this->assertSame([0], $positions());

        $this->postJson('/api/tickets/BH-1/move', ['status' => 'in_progress', 'before_id' => 'BH-3', 'actor' => self::JANICE])
            ->assertUnprocessable()->assertJsonValidationErrors('before_id');
        $this->postJson('/api/tickets/BH-1/move', ['actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('status');

        $moved = TicketEvent::query()->where('type', 'moved')->latest('id')->first();
        $this->assertSame(['status' => 'todo', 'position' => 0], $moved->from);
        $this->assertSame(['status' => 'in_progress', 'position' => 1], $moved->to);
        $this->assertSame([self::JANICE, 'agent', 'api'], [$moved->actor_id, $moved->actor_type, $moved->source]);
    }

    #[Test]
    public function waiting_tilen_ask_waiting_since_done_at_and_auto_ack(): void
    {
        $this->api();
        $t = $this->newTicket();
        $this->postJson("/api/tickets/{$t['key']}/move", ['status' => 'waiting_tilen', 'actor' => self::JANICE])
            ->assertUnprocessable()->assertJsonValidationErrors('ask');
        $w = $this->postJson("/api/tickets/{$t['key']}/move", ['status' => 'waiting_tilen', 'ask' => 'Pick a name?', 'ask_options' => ['Aurora', 'Atlas'], 'actor' => self::JANICE])
            ->assertOk()->json('data');
        $this->assertSame(Carbon::now()->toIso8601String(), $w['waiting_since']);
        $this->assertSame(['Aurora', 'Atlas'], $w['ask_options']);

        // Waiting age levels (ProjectStatus::level): 0-2 d L1, 3-6 d L2, >6 d L3.
        Carbon::setTestNow(Carbon::now()->addDays(3));
        $this->getJson("/api/tickets/{$t['key']}")->assertJsonPath('data.waiting_age_days', 3)->assertJsonPath('data.waiting_level', 2);
        Carbon::setTestNow(Carbon::now()->addDays(4));
        $this->getJson("/api/tickets/{$t['key']}")->assertJsonPath('data.waiting_level', 3);

        // Tilen answers in the UI -> needs PM pickup; ack-input via API clears it.
        $this->postJson("/api/tickets/{$t['key']}/ack-input", ['actor' => self::JANICE])->assertUnprocessable();
        $this->tilenSays($t['key'], ['input_choice' => 'Atlas', 'is_tilen_input' => true])->assertCreated();
        $this->getJson("/api/tickets/{$t['key']}")->assertJsonPath('data.needs_pm_pickup', true)->assertJsonPath('data.status', 'waiting_tilen');
        $this->getJson('/api/tickets?needs_pm_pickup=1')->assertJsonPath('data.0.key', $t['key']);
        $this->travelMinutes(5);
        $this->postJson("/api/tickets/{$t['key']}/ack-input", ['actor' => self::JANICE])->assertOk()->assertJsonPath('data.needs_pm_pickup', false);
        $this->postJson("/api/tickets/{$t['key']}/ack-input", ['actor' => self::JANICE])->assertUnprocessable();

        // "Change answer" -> pickup again; moving out of waiting_tilen auto-acks and clears waiting_since.
        $this->travelMinutes(5);
        $this->tilenSays($t['key'], ['input_choice' => 'Aurora', 'is_tilen_input' => true])->assertCreated();
        $this->travelMinutes(5);
        $m = $this->postJson("/api/tickets/{$t['key']}/move", ['status' => 'in_progress', 'actor' => self::JANICE])->assertOk()->json('data');
        $this->assertFalse($m['needs_pm_pickup']);
        $this->assertNull($m['waiting_since']);
        $this->assertNull($m['waiting_level']);
        $this->assertSame(Carbon::now()->toIso8601String(), $m['tilen_input_ack_at']);
        $this->assertSame(2, TicketEvent::query()->where('type', 'input_acked')->count());
        $this->assertSame(['tilen_input_at', 'via'], array_keys(TicketEvent::query()->where('type', 'input_acked')->latest('id')->first()->to));

        // done_at set on entering done, cleared on leaving.
        $done = $this->postJson("/api/tickets/{$t['key']}/move", ['status' => 'done', 'actor' => self::JANICE])->json('data');
        $this->assertSame(Carbon::now()->toIso8601String(), $done['done_at']);
        $this->assertNull($this->postJson("/api/tickets/{$t['key']}/move", ['status' => 'review', 'actor' => self::JANICE])->json('data.done_at'));
    }

    #[Test]
    public function archive_unarchive_and_no_deletes(): void
    {
        $this->api();
        $a = $this->newTicket();
        $b = $this->newTicket();
        $this->postJson("/api/tickets/{$a['key']}/archive", ['actor' => self::JANICE])->assertOk()->assertJsonPath('data.archived', true);
        $this->assertSame(0, Ticket::find($b['id'])->position);
        $this->patchJson("/api/tickets/{$a['key']}", ['title' => 'x', 'actor' => self::JANICE])->assertStatus(409);
        $this->postJson("/api/tickets/{$a['key']}/move", ['status' => 'done', 'actor' => self::JANICE])->assertStatus(409);
        $this->postJson("/api/tickets/{$a['key']}/comments", ['body' => 'x', 'actor' => self::JANICE])->assertStatus(409);
        $this->postJson("/api/tickets/{$a['key']}/unarchive", ['actor' => self::JANICE])->assertOk()
            ->assertJsonPath('data.archived', false)->assertJsonPath('data.position', 1);
        $this->assertSame(['created', 'archived', 'unarchived'], TicketEvent::query()->where('ticket_id', $a['id'])->orderBy('id')->pluck('type')->all());

        $this->deleteJson("/api/tickets/{$a['key']}")->assertStatus(405);
        $this->deleteJson("/api/tickets/{$a['id']}")->assertStatus(405);
        $this->deleteJson('/api/labels/lb-x')->assertStatus(405);
        $this->assertSame(2, Ticket::count());

        // Archived project: ticket writes 409.
        Project::find(self::BH)->update(['archived_at' => now()]);
        $this->patchJson("/api/tickets/{$b['key']}", ['title' => 'x', 'actor' => self::JANICE])->assertStatus(409);
    }

    #[Test]
    public function comments_are_agent_only_and_retract_is_soft(): void
    {
        $this->api();
        $t = $this->newTicket();
        $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => 'x', 'actor' => 'tilen'])->assertUnprocessable()
            ->assertJsonPath('errors.actor.0', fn ($m) => str_contains($m, "Tilen's input only comes from the Bridge UI"));
        $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => 'x', 'author' => 'tilen'])->assertUnprocessable();
        $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => 'x', 'is_tilen_input' => true, 'actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('is_tilen_input');
        $this->postJson("/api/tickets/{$t['key']}/comments", ['actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => str_repeat('x', 10001), 'actor' => self::JANICE])->assertUnprocessable();

        $c = $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => 'Draft is up', 'actor' => self::JANICE])->assertCreated()->json('data');
        $this->assertSame(['agent', self::JANICE, 'Dr. Janice Polito', false], [$c['author_type'], $c['author_id'], $c['author_name'], $c['is_tilen_input']]);
        $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => 'Second', 'author' => self::ANATOLY])->assertCreated()->assertJsonPath('data.author_id', self::ANATOLY);

        $this->postJson("/api/comments/{$c['id']}/retract", ['actor' => self::ANATOLY])->assertForbidden();
        $this->postJson("/api/comments/{$c['id']}/retract", ['actor' => self::JANICE])->assertOk()->assertJsonPath('data.retracted', true)->assertJsonPath('data.body', null);
        $this->postJson('/api/comments/999/retract', ['actor' => self::JANICE])->assertNotFound();

        $list = $this->getJson("/api/tickets/{$t['key']}/comments")->assertOk()->json('data');
        $this->assertSame([true, false], array_column($list, 'retracted'));
        $this->assertSame([null, 'Second'], array_column($list, 'body'));
        $this->assertSame('Draft is up', TicketComment::find($c['id'])->body); // never hard-deleted
        $this->getJson("/api/tickets/{$t['key']}")->assertJsonPath('data.comment_count', 1);
    }

    #[Test]
    public function labels_api(): void
    {
        $this->api();
        $l = $this->postJson('/api/labels', ['name' => 'M2 launch', 'color' => 'navy', 'actor' => self::JANICE])->assertCreated()->json('data');
        $this->assertStringStartsWith('lb-', $l['id']);
        $this->postJson('/api/labels', ['name' => 'm2 LAUNCH', 'actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/labels', ['name' => 'x', 'color' => 'pink', 'actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('color');
        $this->postJson('/api/labels', ['name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('actor');
        $this->patchJson("/api/labels/{$l['id']}", ['color' => 'green'])->assertOk()->assertJsonPath('data.color', 'green');
        $this->patchJson('/api/labels/lb-nope', ['color' => 'green'])->assertNotFound();
        $this->newTicket(['labels' => ['m2 launch', 'fresh']]);
        $this->assertSame([['M2 launch', 'green', 1], ['fresh', 'grey', 1]], array_map(fn ($r) => [$r['name'], $r['color'], $r['ticket_count']], $this->getJson('/api/labels')->json('data')));
    }

    #[Test]
    public function board_payload(): void
    {
        $this->api();
        $this->newTicket(['title' => 'Todo 1']);
        $w1 = $this->waitingTicket(['title' => 'Waiting older']);
        $this->travelMinutes(10);
        $w2 = $this->waitingTicket(['title' => 'Waiting newer']);
        // waiting_tilen is ordered by waiting_since even when positions say otherwise.
        Ticket::find($w1['id'])->forceFill(['position' => 5])->saveQuietly();
        $old = $this->newTicket(['title' => 'Done long ago', 'status' => 'done']);
        Ticket::find($old['id'])->forceFill(['done_at' => Carbon::now()->subDays(10)])->saveQuietly();
        $this->newTicket(['title' => 'Done today', 'status' => 'done']);
        $arch = $this->newTicket(['title' => 'Archived']);
        $this->postJson("/api/tickets/{$arch['key']}/archive", ['actor' => self::JANICE]);

        $b = $this->getJson('/api/projects/'.self::BH.'/board')->assertOk()->json('data');
        $this->assertSame(['id' => self::BH, 'name' => 'Blue Harbor', 'slug' => 'blue-harbor', 'pm_agent_id' => self::JANICE, 'pm_name' => 'Dr. Janice Polito', 'ticket_prefix' => 'BH'],
            array_intersect_key($b['project'], array_flip(['id', 'name', 'slug', 'pm_agent_id', 'pm_name', 'ticket_prefix'])));
        $this->assertArrayHasKey('status_note', $b['project']);
        $cols = collect($b['columns'])->keyBy('key');
        $this->assertSame(['backlog', 'todo', 'in_progress', 'waiting_tilen', 'review', 'done'], $cols->keys()->all());
        $this->assertSame(['Waiting older', 'Waiting newer'], array_column($cols['waiting_tilen']['tickets'], 'title'));
        $this->assertSame(2, $cols['waiting_tilen']['count']);
        $this->assertSame(['Done today'], array_column($cols['done']['tickets'], 'title'));
        $this->assertSame(1, $b['done_older_count']);
        $this->assertSame(['Todo 1'], array_column($cols['todo']['tickets'], 'title'));

        $since = urlencode(Carbon::now()->subDays(30)->toIso8601String());
        $b = $this->getJson('/api/projects/'.self::BH."/board?done_since=$since&include_archived=1")->json('data');
        $this->assertSame(2, collect($b['columns'])->firstWhere('key', 'done')['count']);
        $this->assertSame(0, $b['done_older_count']);
        $this->assertSame(['Todo 1', 'Archived'], array_column(collect($b['columns'])->firstWhere('key', 'todo')['tickets'], 'title'));
        $this->getJson('/api/projects/proj-nope/board')->assertNotFound();
        $this->assertNotEmpty($w2);
    }

    #[Test]
    public function project_activity_and_ticket_events(): void
    {
        $this->api();
        $t = $this->newTicket();
        $this->travelMinutes(30);
        $since = Carbon::now()->toIso8601String();
        $this->postJson("/api/tickets/{$t['key']}/move", ['status' => 'in_progress', 'actor' => self::JANICE]);
        $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => 'On it', 'actor' => self::JANICE]);

        $all = $this->getJson('/api/projects/'.self::BH.'/activity')->assertOk()->json('data');
        $this->assertSame(['commented', 'moved', 'created'], array_column($all, 'type'));
        $this->assertSame('BH-1', $all[0]['ticket_key']);
        $this->assertSame(['commented', 'moved'], array_column($this->getJson('/api/projects/'.self::BH.'/activity?since='.urlencode($since))->json('data'), 'type'));
        $this->assertCount(1, $this->getJson('/api/projects/'.self::BH.'/activity?limit=1')->json('data'));
        $this->assertSame(['commented', 'moved', 'created'], array_column($this->getJson("/api/tickets/{$t['key']}/events")->assertOk()->json('data'), 'type'));
    }

    #[Test]
    public function every_write_bumps_last_activity(): void
    {
        $this->api();
        $t = $this->newTicket();
        $at = fn () => Project::find(self::BH)->last_activity_at->toIso8601String();
        foreach ([
            fn () => $this->patchJson("/api/tickets/{$t['key']}", ['title' => 'New', 'actor' => self::JANICE]),
            fn () => $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => 'c', 'actor' => self::JANICE]),
            fn () => $this->postJson("/api/tickets/{$t['key']}/move", ['status' => 'review', 'actor' => self::JANICE]),
            fn () => $this->patchJson('/api/projects/'.self::BH.'/status', ['status' => 'smooth', 'actor' => self::JANICE]),
        ] as $write) {
            $this->travelMinutes(1);
            $write()->assertSuccessful();
            $this->assertSame(Carbon::now()->toIso8601String(), $at());
        }
    }
}
