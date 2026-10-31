<?php

namespace Tests\Feature\Screens;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Auth\AccountService;
use App\Services\Tickets\TicketService;
use App\Support\BoardColumns;
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
    public function an_empty_board_keeps_the_pm_real_status_and_uses_the_workspace_waiting_label(): void
    {
        $now = Carbon::now();
        $waiting = BoardColumns::label(BoardColumns::WAITING_TILEN, Project::acrossWorkspaces()->findOrFail(self::BH)->workspace);
        Project::query()->where('id', self::BH)->update([
            'status' => 'waiting', 'status_note' => 'Nothing needs you right now. Build is on track.',
            'status_set_by' => self::JANICE, 'status_set_at' => $now->copy()->subHours(2),
        ]);

        $this->gate()->get('/projects/blue-harbor')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Board')
            ->where('board.project.status', 'waiting')
            ->where('board.project.status_note', 'Nothing needs you right now. Build is on track.')
            ->where('board.project.status_set_by_name', 'Dr. Janice Polito')
            // The PM's real status, not a hardcoded one, and the workspace's waiting column name.
            ->where('board.project.status_label', 'Waiting input')
            ->where('board.columns', fn ($cols) => collect($cols)->pluck('label')->all() === ['Backlog', 'To do', 'In progress', $waiting, 'Review / QA', 'Done'])
            ->where('board.columns', fn ($cols) => collect($cols)->every(fn ($c) => $c['count'] === 0)));
    }

    #[Test]
    public function tickets_carry_blocked_pickup_and_latest_comment_props_for_the_cards(): void
    {
        $this->api();
        $t = $this->waitingTicket(['blocked_reason' => 'Waiting for the weather API key']);
        $this->travelMinutes(1);
        $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => 'Latest from the agent', 'actor' => self::JANICE])->assertCreated();

        $this->gate()->get('/projects/blue-harbor')->assertInertia(fn (Assert $p) => $p
            ->where('board.columns.3.tickets.0.blocked', true)
            ->where('board.columns.3.tickets.0.blocked_reason', 'Waiting for the weather API key')
            ->where('board.columns.3.tickets.0.needs_pm_pickup', false)
            ->where('board.columns.3.tickets.0.latest_comment.author_id', self::JANICE)
            ->where('board.columns.3.tickets.0.latest_comment.author_type', 'agent')
            ->where('board.columns.3.tickets.0.latest_comment.author_name', 'Dr. Janice Polito'));

        // Your input not picked up: Tilen's input comment flips the flag (the "Your input · not picked up yet" chip).
        $this->gate()->postJson("/projects/blue-harbor/tickets/{$t['key']}/comments", ['body' => 'Go with 7:00', 'is_tilen_input' => true])->assertCreated();
        $this->get('/projects/blue-harbor')->assertInertia(fn (Assert $p) => $p
            ->where('board.columns.3.tickets.0.needs_pm_pickup', true));
        // The latest comment is now Tilen's own (the card hides the "via MCP" line for humans).
        $this->get('/projects/blue-harbor')->assertInertia(fn (Assert $p) => $p
            ->where('board.columns.3.tickets.0.latest_comment.author_type', 'human'));
    }

    #[Test]
    public function moving_through_the_ui_endpoints_keeps_the_existing_rules(): void
    {
        $this->api();
        $t = $this->newTicket(['status' => 'todo']);
        $key = $t['key'];

        // Plain move with ordering.
        $this->gate()->postJson("/projects/blue-harbor/tickets/$key/move", ['status' => 'in_progress', 'position' => 0])
            ->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.position', 0);

        // Moving into the waiting column needs an ask; the message names the column as this board shows it.
        $res = $this->postJson("/projects/blue-harbor/tickets/$key/move", ['status' => 'waiting_tilen']);
        $res->assertUnprocessable()->assertJsonValidationErrors('ask');
        $project = Project::acrossWorkspaces()->findOrFail(self::BH);
        $this->assertSame(TicketService::askRequired($project), $res->json('message'));
        $this->assertStringContainsString(BoardColumns::label(BoardColumns::WAITING_TILEN, $project->workspace), $res->json('message'));

        $this->postJson("/projects/blue-harbor/tickets/$key/move", ['status' => 'waiting_tilen', 'ask' => 'Which default?'])
            ->assertOk()->assertJsonPath('data.status', 'waiting_tilen')->assertJsonPath('data.ask', 'Which default?');
        $this->assertNotNull(Ticket::find($t['id'])->waiting_since);

        // Undo (move back) is a plain move the other way; leaving Waiting clears waiting_since.
        $this->postJson("/projects/blue-harbor/tickets/$key/move", ['status' => 'todo', 'position' => 0])
            ->assertOk()->assertJsonPath('data.status', 'todo');
        $this->assertNull(Ticket::find($t['id'])->waiting_since);
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
        $this->gate()->get("/projects/blue-harbor/tickets/{$t['key']}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('ticket.key', $t['key'])
            ->has('ticket.comments')
            ->has('ticket.events'));
        $this->get('/projects/blue-harbor')->assertOk();

        // No draft is ever sent for merely opening/closing: comments and events are unchanged.
        $this->assertSame($comments, TicketComment::count());
        $this->assertSame($events, TicketEvent::count());
    }
}
