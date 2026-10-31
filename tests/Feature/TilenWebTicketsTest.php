<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectReadState;
use App\Models\ProjectStatusEvent;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

class TilenWebTicketsTest extends TestCase
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
    public function web_ticket_routes_need_a_login(): void
    {
        $this->post('/projects/blue-harbor/tickets', ['title' => 'x'])->assertRedirect(route('login'));
        $this->get('/projects/blue-harbor/tickets/BH-1')->assertRedirect(route('login'));
        $this->assertSame(0, Ticket::count());
    }

    #[Test]
    public function quick_add_patch_move_archive_as_tilen(): void
    {
        $t = $this->asOwner()->postJson('/projects/blue-harbor/tickets', ['title' => 'Call the printer'])->assertCreated()->json('data');
        $this->assertSame(['BH-1', 'backlog', 'tilen', 'human'], [$t['key'], $t['status'], $t['created_by'], $t['created_by_type']]);
        $this->postJson('/projects/blue-harbor/tickets', ['title' => 'x', 'status' => 'review'])->assertUnprocessable();
        $this->postJson('/projects/blue-harbor/tickets', ['status' => 'todo'])->assertUnprocessable()->assertJsonValidationErrors('title');

        $this->patchJson('/projects/blue-harbor/tickets/BH-1', ['priority' => 'p1', 'labels' => ['print'], 'assignee' => self::JANICE, 'blocked_reason' => 'Printer closed'])
            ->assertOk()->assertJsonPath('data.priority', 'p1')->assertJsonPath('data.assignee_type', 'agent')->assertJsonPath('data.blocked', true);
        $this->postJson('/projects/blue-harbor/tickets/BH-1/move', ['status' => 'waiting_tilen'])->assertUnprocessable()->assertJsonValidationErrors('ask');
        $this->postJson('/projects/blue-harbor/tickets/BH-1/move', ['status' => 'waiting_tilen', 'ask' => 'Which printer?'])
            ->assertOk()->assertJsonPath('data.status', 'waiting_tilen');
        $this->postJson('/projects/blue-harbor/tickets/BH-1/move', ['status' => 'todo', 'position' => 0])->assertOk()->assertJsonPath('data.position', 0);

        $e = TicketEvent::query()->where('type', 'moved')->latest('id')->first();
        $this->assertSame(['tilen', 'human', 'ui'], [$e->actor_id, $e->actor_type, $e->source]);

        $this->postJson('/projects/blue-harbor/tickets/BH-1/archive')->assertOk()->assertJsonPath('data.archived', true);
        $this->postJson('/projects/blue-harbor/tickets/BH-1/unarchive')->assertOk()->assertJsonPath('data.archived', false);

        // Wrong project / unknown key: 404. Non-JSON requests redirect back.
        $this->patchJson('/projects/northwind/tickets/BH-1', ['priority' => 'p2'])->assertNotFound();
        $this->from('/projects/blue-harbor')->patch('/projects/blue-harbor/tickets/BH-1', ['priority' => 'p2'])
            ->assertStatus(303)->assertRedirect('/projects/blue-harbor');
        $this->assertSame('p2', Ticket::first()->priority);
    }

    #[Test]
    public function tilen_input_comment_sets_pickup_and_reaches_the_feed(): void
    {
        $this->api();
        $t = $this->waitingTicket(['assignee' => self::JANICE]);

        $this->tilenSays($t['key'], ['input_choice' => 'Maybe'])->assertUnprocessable()->assertJsonValidationErrors('input_choice');
        $this->tilenSays($t['key'], ['input_choice' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->tilenSays($t['key'], [])->assertUnprocessable()->assertJsonValidationErrors('body');

        $c = $this->tilenSays($t['key'], ['input_choice' => 'Yes', 'body' => 'Go ahead, keep the blue.', 'is_tilen_input' => true])->assertCreated()->json('data');
        $this->assertSame(['human', 'tilen', 'Tilen', true, 'Yes'], [$c['author_type'], $c['author_id'], $c['author_name'], $c['is_tilen_input'], $c['input_choice']]);
        $this->assertNull($c['picked_up_at']);
        $ticket = Ticket::first();
        $this->assertSame(Carbon::now()->toIso8601String(), $ticket->tilen_input_at->toIso8601String());
        $this->assertTrue($ticket->needsPmPickup());
        $this->assertSame('waiting_tilen', $ticket->status); // the PM moves it, not Tilen's input

        $item = $this->getJson('/api/ticket-comments/feed')->assertOk()->json('data.0');
        $this->assertSame('Go ahead, keep the blue.', $item['body']);
        $this->assertSame(['key' => 'BH-1', 'assignee_id' => self::JANICE, 'assignee_name' => 'Dr. Janice Polito', 'ask' => 'Approve the copy?', 'needs_pm_pickup' => true],
            array_intersect_key($item['ticket'], array_flip(['key', 'assignee_id', 'assignee_name', 'needs_pm_pickup', 'ask'])));
        $this->assertSame('https://bridge.example.test/projects/blue-harbor/tickets/BH-1', $item['ticket']['url']);
        $this->assertSame(['id' => self::BH, 'name' => 'Blue Harbor', 'slug' => 'blue-harbor', 'pm_agent_id' => self::JANICE, 'pm_name' => 'Dr. Janice Polito'], $item['project']);

        // Change answer: a choice alone is enough (body = the choice); the old input stays.
        $this->travelMinutes(3);
        $this->tilenSays($t['key'], ['input_choice' => 'No'])->assertCreated()->assertJsonPath('data.body', 'No');
        $this->assertSame(2, TicketComment::query()->where('is_tilen_input', true)->count());
        $this->postJson("/api/tickets/{$t['key']}/ack-input", ['actor' => self::JANICE])->assertOk();
        $comments = $this->getJson("/api/tickets/{$t['key']}/comments")->json('data');
        $this->assertNotNull($comments[1]['picked_up_at']);

        // A plain comment from Tilen is not an input; Tilen can mark input on any ticket.
        $other = $this->newTicket(['status' => 'in_progress']);
        $this->tilenSays($other['key'], ['body' => 'Looks good'])->assertCreated()->assertJsonPath('data.is_tilen_input', false);
        $this->assertFalse(Ticket::find($other['id'])->needsPmPickup());
        $this->tilenSays($other['key'], ['body' => 'Ship Friday', 'is_tilen_input' => true])->assertCreated();
        $this->assertTrue(Ticket::find($other['id'])->needsPmPickup());
    }

    #[Test]
    public function acked_input_keeps_the_answered_state_until_the_pm_asks_something_new(): void
    {
        // QA M1: the PM acks while the ticket stays in Waiting on Tilen -> the Your input box shows his answer, not a blank form.
        $this->api();
        $t = $this->waitingTicket(['assignee' => self::JANICE]);
        $this->assertFalse($this->getJson("/api/tickets/{$t['key']}")->assertOk()->json('data.tilen_answered_ask'));

        $this->travelMinutes(1);
        $this->tilenSays($t['key'], ['input_choice' => 'Yes'])->assertCreated();
        $this->assertTrue($this->getJson("/api/tickets/{$t['key']}")->json('data.tilen_answered_ask')); // sent, not picked up

        $this->travelMinutes(1);
        $acked = $this->postJson("/api/tickets/{$t['key']}/ack-input", ['actor' => self::JANICE])->assertOk()->json('data');
        $this->assertSame(['waiting_tilen', false, true], [$acked['status'], $acked['needs_pm_pickup'], $acked['tilen_answered_ask']]);

        // Still answered after a reorder inside the column or an unrelated edit.
        $this->travelMinutes(1);
        $this->patchJson("/api/tickets/{$t['key']}", ['priority' => 'p1', 'actor' => self::JANICE])->assertOk()->assertJsonPath('data.tilen_answered_ask', true);

        // A new ask after his answer -> a fresh blank form.
        $this->travelMinutes(1);
        $this->patchJson("/api/tickets/{$t['key']}", ['ask' => 'And the footer copy?', 'actor' => self::JANICE])->assertOk()->assertJsonPath('data.tilen_answered_ask', false);

        // Answer again, ack, then the PM moves it out and back in -> a new round, blank form again.
        $this->travelMinutes(1);
        $this->tilenSays($t['key'], ['input_choice' => 'No'])->assertCreated();
        $this->postJson("/api/tickets/{$t['key']}/ack-input", ['actor' => self::JANICE])->assertOk()->assertJsonPath('data.tilen_answered_ask', true);
        $this->travelMinutes(1);
        $this->postJson("/api/tickets/{$t['key']}/move", ['status' => 'in_progress', 'actor' => self::JANICE])->assertOk()->assertJsonPath('data.tilen_answered_ask', false);
        $this->travelMinutes(1);
        $this->postJson("/api/tickets/{$t['key']}/move", ['status' => 'waiting_tilen', 'ask' => 'And the footer copy?', 'actor' => self::JANICE])->assertOk()->assertJsonPath('data.tilen_answered_ask', false);
    }

    #[Test]
    public function read_states_drive_unread_for_tilen(): void
    {
        $this->api();
        $t = $this->waitingTicket();
        $plain = $this->newTicket(['status' => 'in_progress']);
        $unread = fn (string $key) => $this->getJson("/api/tickets/$key")->json('data');

        $this->travelMinutes(1);
        $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => 'Context added', 'actor' => self::JANICE])->assertCreated();
        $this->postJson("/api/tickets/{$plain['key']}/comments", ['body' => 'Internal note', 'actor' => self::JANICE])->assertCreated();
        $d = $unread($t['key']);
        $this->assertTrue($d['unread_for_tilen']);
        $this->assertSame(['id' => self::JANICE, 'name' => 'Dr. Janice Polito'], $d['latest_unread_author']);
        // Not waiting on Tilen and he never commented: no unread strip.
        $this->assertFalse($unread($plain['key'])['unread_for_tilen']);

        $this->travelMinutes(1);
        $this->asOwner()->postJson("/projects/blue-harbor/tickets/{$t['key']}/seen")->assertOk()->assertJsonPath('data.unread_for_tilen', false);
        $this->travelMinutes(1);
        $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => 'One more', 'actor' => self::ANATOLY])->assertCreated();
        $this->assertSame('Anatoly Korenchkin', $unread($t['key'])['latest_unread_author']['name']);

        // Once Tilen commented on a ticket, agent replies there are unread too.
        $this->tilenSays($plain['key'], ['body' => 'Why?'])->assertCreated();
        $this->travelMinutes(1);
        $this->postJson("/api/tickets/{$plain['key']}/comments", ['body' => 'Because', 'actor' => self::JANICE])->assertCreated();
        $this->assertTrue($unread($plain['key'])['unread_for_tilen']);
        // Board carries the flag as well.
        $board = collect($this->getJson('/api/projects/'.self::BH.'/board')->json('data.columns'))->pluck('tickets')->flatten(1);
        $this->assertSame([true, true], $board->pluck('unread_for_tilen')->all());
    }

    #[Test]
    public function project_seen_and_tilen_status_override(): void
    {
        $this->api();
        $this->patchJson('/api/projects/'.self::BH.'/status', ['status' => 'waiting', 'note' => 'Need a call', 'actor' => self::JANICE])->assertOk();
        $this->getJson('/api/projects/'.self::BH.'/board')->assertJsonPath('data.project.status_unseen_by_tilen', true);
        $this->asOwner()->postJson('/projects/blue-harbor/seen')->assertOk()->assertJsonPath('data.status_unseen_by_tilen', false);
        $this->assertNotNull(ProjectReadState::first()->status_seen_at);

        $this->travelMinutes(1);
        $this->asOwner()->patchJson('/projects/blue-harbor/status', ['status' => 'smooth', 'note' => 'Sorted on the phone'])->assertOk()
            ->assertJsonPath('data.current.set_by', 'tilen')->assertJsonPath('data.current.set_by_name', 'Tilen');
        $e = ProjectStatusEvent::query()->latest('created_at')->first();
        $this->assertSame(['waiting', 'smooth', 'tilen', 'human', 'ui'], [$e->from_status, $e->to_status, $e->set_by, $e->set_by_type, $e->source]);
        // Tilen may archive and reopen.
        $this->patchJson('/projects/blue-harbor/status', ['status' => 'archived'])->assertOk();
        $this->assertNotNull(Project::find(self::BH)->archived_at);
        $this->patchJson('/projects/blue-harbor/status', ['status' => 'smooth'])->assertOk();
        $this->assertNull(Project::find(self::BH)->archived_at);
        $this->patchJson('/projects/blue-harbor/status', ['status' => 'nope'])->assertUnprocessable();
        // His own change doesn't toast.
        $this->getJson('/api/projects/'.self::BH.'/board')->assertJsonPath('data.project.status_unseen_by_tilen', false);
    }

    #[Test]
    public function deep_link_renders_the_board_with_the_ticket_and_existing_pages_still_render(): void
    {
        $this->api();
        $this->waitingTicket();
        $this->asOwner()->get('/projects/blue-harbor/tickets/BH-1')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Board')->where('ticket.key', 'BH-1')->where('board.project.ticket_prefix', 'BH')->has('ticket.comments'));
        $this->assertSame(route('projects.tickets.show', ['slug' => 'blue-harbor', 'key' => 'BH-1']), url('/projects/blue-harbor/tickets/BH-1'));
        $this->get('/projects/blue-harbor/tickets/BH-9')->assertNotFound();
        $this->get('/projects/northwind/tickets/BH-1')->assertNotFound();

        $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Portfolio')->has('projects', 2)
            ->where('projects.0.waitingTilenCount', fn ($n) => is_int($n)));
        $this->get('/projects/northwind')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Board')->where('board.project.ticket_prefix', 'NW'));
    }
}
