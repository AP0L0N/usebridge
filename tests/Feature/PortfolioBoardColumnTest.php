<?php

namespace Tests\Feature;

use App\Models\Workstream;
use App\Services\Tickets\Workstreams;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/**
 * The Portfolio's board column: /portfolio?project={slug}&ws={id} renders the workstream's Kanban board as the next
 * column (same props as the Board page), so reload / Back keep it open; the dedicated Board page keeps working.
 */
class PortfolioBoardColumnTest extends TestCase
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

    private function frontend(): Workstream
    {
        $id = $this->asOwner()->postJson('/projects/blue-harbor/workstreams', ['name' => 'Frontend'])->assertCreated()->json('data.id');

        return Workstream::query()->findOrFail($id);
    }

    /** @return list<string> */
    private static function titles(mixed $board): array
    {
        return collect($board->toArray()['columns'])->flatMap(fn ($c) => array_column($c['tickets'], 'title'))->all();
    }

    #[Test]
    public function selecting_a_workstream_opens_its_board_as_the_next_column(): void
    {
        $front = $this->frontend();
        $this->postJson('/projects/blue-harbor/tickets', ['title' => 'In front', 'workstream' => $front->id])->assertCreated();
        $this->postJson('/projects/blue-harbor/tickets', ['title' => 'In general'])->assertCreated();

        $this->get("/portfolio?project=blue-harbor&ws={$front->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Portfolio')
            ->where('selected', 'blue-harbor')
            ->where('workstream', $front->id)
            ->where('board', fn ($b) => self::titles($b) === ['In front'])
            ->where('board.project.slug', 'blue-harbor')
            ->has('statusHistory')
            ->has('people')
            ->has('labels')
            ->where('doneAll', false)
            ->where('ticket', null)
            ->has('projects'));
        // A workstream name resolves too (like the Board page's ?ws=).
        $this->get('/portfolio?project=blue-harbor&ws=frontend')->assertInertia(fn (Assert $p) => $p->where('workstream', $front->id));
    }

    #[Test]
    public function without_a_workstream_or_with_an_unknown_one_the_preview_shows(): void
    {
        foreach (['/portfolio?project=blue-harbor', '/portfolio?project=blue-harbor&ws=nope', '/portfolio?ws=general'] as $url) {
            $this->asOwner()->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->where('workstream', null)->where('board', null)->where('ticket', null));
        }
    }

    #[Test]
    public function opening_the_board_column_clears_that_workstreams_badge_in_the_same_payload(): void
    {
        $front = $this->frontend();
        $this->travelMinutes(1);
        $this->api()->newTicket(['workstream' => $front->id]);
        $owner = $this->workspaceOwner();
        $this->assertSame(1, Workstreams::badges([$front->id], $owner)[$front->id]);

        $this->travelMinutes(1);
        $this->asOwner()->get("/portfolio?project=blue-harbor&ws={$front->id}")->assertInertia(fn (Assert $p) => $p
            ->where('projects', fn ($projects) => collect(collect($projects)->firstWhere('id', self::BH)['workstreams'])->firstWhere('id', $front->id)['badge'] === 0));
        $this->assertSame([], Workstreams::badges([$front->id], $owner));
    }

    #[Test]
    public function a_ticket_opens_over_the_board_column_and_only_from_that_project(): void
    {
        $general = Workstream::query()->where('project_id', self::BH)->where('is_default', true)->firstOrFail();
        $bh = $this->api()->newTicket();
        $nw = $this->newTicket([], self::NW);

        $this->asOwner()->get("/portfolio?project=blue-harbor&ws={$general->id}&ticket={$bh['key']}")->assertInertia(fn (Assert $p) => $p
            ->where('ticket.key', $bh['key'])->has('ticket.comments'));
        $this->get("/portfolio?project=blue-harbor&ws={$general->id}&ticket={$nw['key']}")->assertInertia(fn (Assert $p) => $p->where('ticket', null));
    }

    #[Test]
    public function show_older_done_works_in_the_column(): void
    {
        $general = Workstream::query()->where('project_id', self::BH)->where('is_default', true)->firstOrFail();

        $this->asOwner()->get("/portfolio?project=blue-harbor&ws={$general->id}&done=all")->assertInertia(fn (Assert $p) => $p->where('doneAll', true));
    }

    #[Test]
    public function the_dedicated_board_page_still_renders(): void
    {
        $front = $this->frontend();

        $this->asOwner()->get("/projects/blue-harbor?ws={$front->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Board')->where('workstream', $front->id)->has('board')->has('openAsks')->has('questions')->has('now'));
    }
}
