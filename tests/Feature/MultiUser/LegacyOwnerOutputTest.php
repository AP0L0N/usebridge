<?php

namespace Tests\Feature\MultiUser;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketReadState;
use App\Services\Tickets\Actor;
use App\Services\Tickets\TicketService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\McpHelpers;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/**
 * B2, backwards compatibility: in the legacy default workspace (bridge.default_workspace, all pre-multi-user data) the
 * owner keeps the actor id 'tilen'. Existing rows written as 'tilen' still read "Tilen" (the owner's first name), the
 * waiting label stays what it was, unread / read states keep working, and the owner's web writes are still 'tilen'.
 */
class LegacyOwnerOutputTest extends TestCase
{
    use McpHelpers, RefreshDatabase, TicketHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
        // The backfill's owner of the existing workspace (as on LANTIS).
        $this->assertSame('Tilen', strtok($this->workspaceOwner()->name, ' '));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Pre-multi-user rows: a ticket created by 'tilen' in the UI, with his input comment and his read state. */
    private function legacyTicket(): Ticket
    {
        $tickets = app(TicketService::class);
        $ticket = $tickets->create(Project::query()->findOrFail(self::BH), ['title' => 'Approve the copy', 'status' => 'waiting_tilen', 'ask' => 'Ship it?', 'ask_options' => ['Yes', 'No']], Actor::tilen());
        $tickets->comment($ticket, Actor::tilen(), 'Yes', true, 'Yes');
        TicketReadState::query()->create(['user' => 'tilen', 'ticket_id' => $ticket->id, 'last_seen_at' => Carbon::now()]);

        return $ticket->refresh();
    }

    #[Test]
    public function existing_rows_still_read_tilen_on_the_api_mcp_and_web(): void
    {
        $ticket = $this->legacyTicket();
        $this->travelMinutes(5);
        $this->api()->postJson("/api/tickets/{$ticket->key}/comments", ['body' => 'Shipping today.', 'actor' => self::JANICE])->assertCreated();

        $t = $this->getJson("/api/tickets/{$ticket->key}")->assertOk()->json('data');
        $this->assertSame(['tilen', 'Tilen'], [$t['created_by'], $t['created_by_name']]);
        $this->assertSame([['tilen', 'Tilen', true], [self::JANICE, 'Dr. Janice Polito', false]], array_map(fn ($c) => [$c['author_id'], $c['author_name'], $c['is_tilen_input']], $t['comments']));
        $this->assertSame('Waiting on Tilen', $t['status_label']);
        $this->assertTrue($t['waiting_on_tilen']);
        $this->assertTrue($t['unread_for_tilen']); // his read state ('tilen') predates Janice's reply
        $this->assertSame($t['unread_for_tilen'], $t['unread_for_me']);
        $this->assertSame([$ticket->key], array_column($this->getJson('/api/tickets?unread_for_tilen=1')->json('data'), 'key'));
        $this->assertSame([$ticket->key], array_column($this->getJson('/api/tickets?unread_for_owner=1')->json('data'), 'key'));
        $this->assertSame('Waiting on Tilen', $this->getJson('/api/board-columns')->json('data.3.label'));
        $this->assertSame(['tilen'], array_column($this->getJson('/api/ticket-comments/feed')->json('data'), 'author_id')); // default: people's comments
        $this->assertSame(['tilen'], array_column($this->getJson('/api/ticket-comments/feed?author=tilen')->json('data'), 'author_id'));
        $this->getJson('/api/tickets?assignee=tilen')->assertOk();

        $owner = $this->workspaceOwner();
        $token = $this->scopedToken($owner, app(CurrentWorkspace::class)->default(), 'read');
        $this->assertSame('Tilen', $this->tool($token, 'get_ticket', ['key' => $ticket->key])['data']['created_by_name']);

        // The owner on the web: same names, the "You" person is 'tilen', and opening the ticket updates his read state.
        $this->asOwner()->get("/projects/blue-harbor/tickets/{$ticket->key}")->assertOk()->assertInertia(fn ($p) => $p
            ->where('auth.user.actor_id', 'tilen')
            ->where('people.0', ['id' => 'tilen', 'name' => 'Tilen', 'role' => 'You', 'type' => 'human'])
            ->where('ticket.created_by_name', 'Tilen')
            ->where('ticket.comments.0.author_name', 'Tilen')
            ->where('ticket.unread_for_tilen', true)
            ->where('board.columns.3.label', 'Waiting on Tilen'));
        $this->postJson("/projects/blue-harbor/tickets/{$ticket->key}/seen")->assertOk()->assertJsonPath('data.unread_for_tilen', false);
        $this->assertSame(['tilen'], TicketReadState::query()->where('ticket_id', $ticket->id)->pluck('user')->all());
    }

    #[Test]
    public function the_owners_web_writes_are_still_recorded_as_tilen(): void
    {
        $key = $this->asOwner()->postJson('/projects/blue-harbor/tickets', ['title' => 'Call the printer'])->assertCreated()
            ->assertJsonPath('data.created_by', 'tilen')->assertJsonPath('data.created_by_name', 'Tilen')->json('data.key');
        $this->postJson("/projects/blue-harbor/tickets/$key/comments", ['body' => 'Before noon.'])->assertCreated()
            ->assertJsonPath('data.author_id', 'tilen')->assertJsonPath('data.author_name', 'Tilen');
        $this->patchJson("/projects/blue-harbor/tickets/$key", ['assignee' => 'tilen'])->assertOk()
            ->assertJsonPath('data.assignee_id', 'tilen')->assertJsonPath('data.assignee_name', 'Tilen');
        $this->patchJson('/projects/blue-harbor/status', ['status' => 'smooth'])->assertOk()->assertJsonPath('data.event.set_by', 'tilen');

        $this->api()->getJson('/api/projects/'.self::BH)->assertJsonPath('data.status_source', 'tilen');
        $this->getJson("/api/tickets/$key/events")->assertOk();
        $this->assertSame(['tilen'], Ticket::query()->where('key', $key)->firstOrFail()->events()->distinct()->pluck('actor_id')->all());

        // A member of the same workspace is recorded as themselves, not as the owner.
        $member = $this->workspaceUser(['name' => 'Morgan Member']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($member, 'web')->postJson("/projects/blue-harbor/tickets/$key/comments", ['body' => 'Done.'])->assertCreated()
            ->assertJsonPath('data.author_id', 'user:'.$member->id)->assertJsonPath('data.author_name', 'Morgan Member');
    }

    #[Test]
    public function the_smooth_status_warning_still_names_the_owner(): void
    {
        $this->legacyTicket();
        $this->asOwner()->patchJson('/projects/blue-harbor/status', ['status' => 'smooth'])->assertOk()
            ->assertJsonPath('warnings.0.message', 'Project status is Smooth sailing but 1 ticket wait on Tilen.');
    }
}
