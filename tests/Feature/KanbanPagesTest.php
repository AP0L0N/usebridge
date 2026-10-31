<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketReadState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/** Kanban UI Inertia props: portfolio cards, the board, the ticket deep link and the seen endpoints. */
class KanbanPagesTest extends TestCase
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

    private function cards(): array
    {
        $out = null;
        $this->asOwner()->get('/')->assertOk()->assertInertia(function (Assert $p) use (&$out) {
            $p->component('Portfolio');
            $out = $p->toArray()['props']['projects'];
        });

        return $out;
    }

    private function setStatus(string $id, string $name, ?string $status, ?Carbon $at, ?Carbon $activity = null): void
    {
        Project::query()->updateOrCreate(['id' => $id], [
            'name' => $name, 'slug' => Str::slug($name), 'owner_agent_id' => self::JANICE,
            'status' => $status, 'status_set_by' => $status ? self::JANICE : null, 'status_set_at' => $at,
            'status_note' => $status ? "$name note" : null, 'last_activity_at' => $activity ?? $at,
        ]);
    }

    #[Test]
    public function portfolio_cards_use_the_pm_status_and_follow_the_spec_order(): void
    {
        $now = Carbon::now();
        $this->setStatus(self::NW, 'Northwind', 'smooth', $now->copy()->subDays(2), $now->copy()->subDays(2));
        $this->setStatus(self::BH, 'Blue Harbor', 'waiting', $now->copy()->subDays(1));
        $this->setStatus('proj-a', 'Alpha', 'waiting', $now->copy()->subDays(8));    // L3
        $this->setStatus('proj-b', 'Bravo', 'waiting', $now->copy()->subDays(4));    // L2
        $this->setStatus('proj-c', 'Charlie', 'waiting', $now->copy()->subHours(30)); // L1, older than BH
        $this->setStatus('proj-d', 'Delta', 'smooth', $now->copy()->subDays(5), $now->copy()->subHour());
        $this->setStatus('proj-e', 'Echo', null, null, $now);
        $this->setStatus('proj-f', 'Foxtrot', 'archived', $now->copy()->subDays(3));
        Project::find('proj-f')->forceFill(['archived_at' => $now->copy()->subDays(3)])->save();

        $cards = $this->cards();
        $this->assertSame(
            ['Alpha', 'Bravo', 'Charlie', 'Blue Harbor', 'Delta', 'Northwind', 'Echo', 'Foxtrot'],
            array_column($cards, 'name'),
        );
        $by = collect($cards)->keyBy('name');
        $this->assertSame([3, 8], [$by['Alpha']['pmLevel'], $by['Alpha']['pmAgeDays']]);
        $this->assertSame([2, 4], [$by['Bravo']['pmLevel'], $by['Bravo']['pmAgeDays']]);
        $this->assertSame(1, $by['Blue Harbor']['pmLevel']);
        $this->assertNull($by['Delta']['pmLevel']);
        // Status not set: null status, no setter, no note (the UI shows "Status not set").
        $this->assertSame([null, null, null], [$by['Echo']['pmStatus'], $by['Echo']['pmStatusSetByName'], $by['Echo']['pmStatusNote']]);
        $this->assertSame(['Dr. Janice Polito', 'Dr. Janice Polito', 'Blue Harbor note'], [$by['Blue Harbor']['pmName'], $by['Blue Harbor']['pmStatusSetByName'], $by['Blue Harbor']['pmStatusNote']]);
    }

    #[Test]
    public function portfolio_counts_tickets_per_column_and_for_you(): void
    {
        $this->api();
        $this->waitingTicket();
        $this->waitingTicket();
        $this->newTicket(['status' => 'in_progress']);
        $gone = $this->newTicket(['status' => 'in_progress']);
        $this->postJson("/api/tickets/{$gone['key']}/archive", ['actor' => self::JANICE])->assertOk();
        // Done counts only the last 7 days (legend "Done (7 days)").
        $recent = $this->newTicket(['status' => 'done']);
        $old = $this->newTicket(['status' => 'done']);
        Ticket::query()->where('key', $old['key'])->update(['done_at' => now()->subDays(8)]);

        $ca = collect($this->cards())->firstWhere('id', self::BH);
        $this->assertSame(2, $ca['waitingTilenCount']);
        $this->assertSame(['backlog' => 0, 'todo' => 0, 'in_progress' => 1, 'waiting_tilen' => 2, 'review' => 0, 'done' => 1], $ca['ticketCounts']);
    }

    #[Test]
    public function board_props_have_six_columns_waiting_oldest_first_and_done_older_count(): void
    {
        $this->api();
        $first = $this->waitingTicket(['title' => 'Newer ask']);
        $this->travelMinutes(-60 * 24);
        $old = $this->waitingTicket(['title' => 'Older ask']);
        Ticket::query()->whereKey($old['id'])->update(['waiting_since' => Carbon::now()->subDays(3)]);
        $this->travelMinutes(60 * 24);
        foreach ([1, 3, 9, 20] as $d) {
            $t = $this->newTicket(['status' => 'done', 'title' => "done $d"]);
            Ticket::query()->whereKey($t['id'])->update(['done_at' => Carbon::now()->subDays($d)]);
        }

        $this->asOwner()->get('/projects/blue-harbor')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Board')
            ->where('board.columns', fn ($cols) => collect($cols)->pluck('key')->all() === ['backlog', 'todo', 'in_progress', 'waiting_tilen', 'review', 'done'])
            ->where('board.columns.3.tickets.0.title', 'Older ask')
            ->where('board.columns.3.tickets.1.key', $first['key'])
            ->where('board.columns.5.count', 2)
            ->where('board.done_older_count', 2)
            ->where('doneAll', false)
            ->where('ticket', null)
            ->where('people.0.id', 'tilen')
            ->has('labels')
            ->has('statusHistory', 0));

        $this->get('/projects/blue-harbor?done=all')->assertInertia(fn (Assert $p) => $p
            ->where('doneAll', true)->where('board.columns.5.count', 4)->where('board.done_older_count', 0));
    }

    #[Test]
    public function board_status_history_is_newest_first_and_the_toast_flag_clears_on_project_seen(): void
    {
        $this->api();
        $this->patchJson('/api/projects/'.self::BH.'/status', ['status' => 'smooth', 'note' => 'first', 'actor' => self::JANICE])->assertOk();
        $this->travelMinutes(5);
        $this->patchJson('/api/projects/'.self::BH.'/status', ['status' => 'waiting', 'note' => 'second', 'actor' => self::JANICE])->assertOk();

        $this->asOwner()->get('/projects/blue-harbor')->assertInertia(fn (Assert $p) => $p
            ->has('statusHistory', 2)->where('statusHistory.0.note', 'second')->where('statusHistory.0.from_status', 'smooth')
            ->where('board.project.status_unseen_by_tilen', true));

        $this->postJson('/projects/blue-harbor/seen')->assertOk();
        $this->get('/projects/blue-harbor')->assertInertia(fn (Assert $p) => $p->where('board.project.status_unseen_by_tilen', false));
    }

    #[Test]
    public function ticket_deep_link_renders_the_board_with_the_ticket_detail(): void
    {
        $this->api();
        $t = $this->waitingTicket(['description' => 'Some **markdown**']);
        $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => 'Hello Tilen', 'actor' => self::JANICE])->assertCreated();

        $this->asOwner()->get("/projects/blue-harbor/tickets/{$t['key']}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Board')
            ->where('ticket.key', $t['key'])
            ->where('ticket.ask_options', ['Yes', 'No'])
            ->where('ticket.tilen_last_seen_at', null)
            ->has('ticket.comments', 1)
            ->has('ticket.events')
            ->has('board.columns', 6));
        // lower-case key works too; another project's ticket is a 404
        $this->get('/projects/blue-harbor/tickets/'.strtolower($t['key']))->assertOk();
        $this->get("/projects/northwind/tickets/{$t['key']}")->assertNotFound();
    }

    #[Test]
    public function opening_a_ticket_marks_it_seen_and_clears_the_unread_strip(): void
    {
        $this->api();
        $t = $this->waitingTicket();
        $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => 'New info', 'actor' => self::JANICE])->assertCreated();
        $this->asOwner()->get('/projects/blue-harbor')->assertInertia(fn (Assert $p) => $p
            ->where('board.columns.3.tickets.0.unread_for_tilen', true)
            ->where('board.columns.3.tickets.0.latest_unread_author.name', 'Dr. Janice Polito'));

        $this->travelMinutes(1);
        $this->postJson("/projects/blue-harbor/tickets/{$t['key']}/seen")->assertOk()->assertJsonPath('data.unread_for_tilen', false);
        $this->assertNotNull(TicketReadState::query()->where('user', 'tilen')->where('ticket_id', $t['id'])->value('last_seen_at'));

        $this->get('/projects/blue-harbor')->assertInertia(fn (Assert $p) => $p->where('board.columns.3.tickets.0.unread_for_tilen', false));
        $this->get("/projects/blue-harbor/tickets/{$t['key']}")->assertInertia(fn (Assert $p) => $p->whereNot('ticket.tilen_last_seen_at', null));
    }

    #[Test]
    public function the_seen_endpoints_need_a_login(): void
    {
        $this->post('/projects/blue-harbor/seen')->assertRedirect(route('login'));
        $this->post('/projects/blue-harbor/tickets/BH-1/seen')->assertRedirect(route('login'));
    }
}
