<?php

namespace Tests\Feature\Screens;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Auth\AccountService;
use App\Services\Tickets\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/**
 * Redesigned board + ticket drawer (screen 2): the props that drive each state (empty board with the PM's real
 * status, the waiting column's label (the workspace's own name), blocked, your-input-not-picked-up, the card "via MCP" line), the move
 * flow through the existing endpoints, and workspace isolation. Discard-on-close is client-only: nothing is
 * persisted until a submit (see tests/browser for the dialog itself).
 */
class BoardScreenTest extends TestCase
{
    use RefreshDatabase;
    use TicketHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->bootTickets();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function an_empty_board_keeps_the_pm_real_status_and_has_no_waiting_column(): void
    {
        $now = Carbon::now();
        Project::query()->where('id', self::BH)->update([
            'status' => 'waiting', 'status_note' => 'Nothing needs you right now. Build is on track.',
            'status_set_by' => self::JANICE, 'status_set_at' => $now->copy()->subHours(2),
        ]);

        $this->asOwner()->get('/projects/blue-harbor')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Board')
            ->where('board.project.status', 'waiting')
            ->where('board.project.status_note', 'Nothing needs you right now. Build is on track.')
            ->where('board.project.status_set_by_name', 'Dr. Janice Polito')
            // The PM's real status, not a hardcoded one; the five columns, no waiting column.
            ->where('board.project.status_label', 'Waiting input')
            ->where('board.columns', fn ($cols) => collect($cols)->pluck('label')->all() === ['Backlog', 'To do', 'In progress', 'Review / QA', 'Done'])
            ->where('board.columns', fn ($cols) => collect($cols)->every(fn ($c) => $c['count'] === 0)));
    }

    #[Test]
    public function tickets_carry_blocked_pickup_waiting_and_latest_comment_props_for_the_cards(): void
    {
        $this->api();
        $t = $this->waitingTicket(['blocked_reason' => 'Waiting for the weather API key']);
        $this->travelMinutes(1);
        $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => 'Latest from the agent', 'actor' => self::JANICE])->assertCreated();

        $this->asOwner()->get('/projects/blue-harbor')->assertInertia(fn (Assert $p) => $p
            ->where('board.columns.1.tickets.0.blocked', true)
            ->where('board.columns.1.tickets.0.blocked_reason', 'Waiting for the weather API key')
            ->where('board.columns.1.tickets.0.needs_pm_pickup', false)
            // Flagged, and Tilen has sent no input since: the card is highlighted.
            ->where('board.columns.1.tickets.0.waiting_on_input', true)
            ->where('board.columns.1.tickets.0.waiting_on_input_outstanding', true)
            ->where('board.columns.1.tickets.0.latest_comment.author_id', self::JANICE)
            ->where('board.columns.1.tickets.0.latest_comment.author_type', 'agent')
            ->where('board.columns.1.tickets.0.latest_comment.author_name', 'Dr. Janice Polito'));

        // Your input not picked up: Tilen's input comment flips needs_pm_pickup (the "Your input · not picked up yet"
        // chip) and ends the highlight; the waiting-on-input flag itself stays.
        $this->travelMinutes(1);
        $this->asOwner()->postJson("/projects/blue-harbor/tickets/{$t['key']}/comments", ['body' => 'Go with 7:00', 'is_tilen_input' => true])->assertCreated();
        $this->get('/projects/blue-harbor')->assertInertia(fn (Assert $p) => $p
            ->where('board.columns.1.tickets.0.needs_pm_pickup', true)
            ->where('board.columns.1.tickets.0.waiting_on_input', true)
            ->where('board.columns.1.tickets.0.waiting_on_input_outstanding', false));
        // The latest comment is now Tilen's own (the card hides the "via MCP" line for humans).
        $this->get('/projects/blue-harbor')->assertInertia(fn (Assert $p) => $p
            ->where('board.columns.1.tickets.0.latest_comment.author_type', 'human'));
    }

    #[Test]
    public function moving_through_the_ui_endpoints_keeps_the_existing_rules(): void
    {
        $this->api();
        $t = $this->newTicket(['status' => 'todo']);
        $key = $t['key'];

        // Plain move with ordering.
        $this->asOwner()->postJson("/projects/blue-harbor/tickets/$key/move", ['status' => 'in_progress', 'position' => 0])
            ->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.position', 0);

        // The waiting column is gone: no move into it, with or without an ask; the message says what replaced it.
        $res = $this->postJson("/projects/blue-harbor/tickets/$key/move", ['status' => 'waiting_tilen', 'ask' => 'Which default?']);
        $res->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(TicketService::WAITING_COLUMN_REMOVED, $res->json('message'));
        $this->assertSame(['in_progress', null], [Ticket::find($t['id'])->status, Ticket::find($t['id'])->ask]);

        // A move never needs an ask and never touches the waiting-on-input flag.
        $this->patchJson("/projects/blue-harbor/tickets/$key", ['waiting_on_input' => true])->assertOk()->assertJsonPath('data.waiting_on_input', true);
        $this->postJson("/projects/blue-harbor/tickets/$key/move", ['status' => 'todo', 'position' => 0])
            ->assertOk()->assertJsonPath('data.status', 'todo')->assertJsonPath('data.waiting_on_input', true);
        $this->assertNotNull(Ticket::find($t['id'])->waiting_on_input_at);
    }

    #[Test]
    public function the_board_and_ticket_endpoints_stay_inside_the_workspace(): void
    {
        $this->api();
        $t = $this->newTicket(['status' => 'todo']);

        // A logged-in user from another workspace sees no project, no ticket (404); guests are sent to log in.
        // Guests are sent to log in.
        $this->get('/projects/blue-harbor')->assertRedirect(route('login'));

        $marta = app(AccountService::class)->register('Marta Example', 'marta@example.test', 'garden-planner-27', 'Marta Labs');
        $this->actingAs($marta, 'web')->get('/projects/blue-harbor')->assertNotFound();
        $this->actingAs($marta, 'web')->postJson("/projects/blue-harbor/tickets/{$t['key']}/move", ['status' => 'done'])->assertNotFound();
    }

    #[Test]
    public function opening_and_closing_a_ticket_persists_nothing_but_the_seen_state(): void
    {
        $this->api();
        $t = $this->waitingTicket();
        $comments = TicketComment::count();
        $events = TicketEvent::count();

        // Open the drawer (deep link), read the props a client needs for the discard check, close (Back).
        $this->asOwner()->get("/projects/blue-harbor/tickets/{$t['key']}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('ticket.key', $t['key'])
            ->has('ticket.comments')
            ->has('ticket.events'));
        $this->get('/projects/blue-harbor')->assertOk();

        // No draft is ever sent for merely opening/closing: comments and events are unchanged.
        $this->assertSame($comments, TicketComment::count());
        $this->assertSame($events, TicketEvent::count());
    }
}
