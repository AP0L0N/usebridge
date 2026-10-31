<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/** GET /api/ticket-comments/feed: Captain's answers relay. */
class TicketCommentsFeedTest extends TestCase
{
    use RefreshDatabase;
    use TicketHelpers;

    private array $ca;

    private array $mw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
        $this->api();
        $this->ca = $this->waitingTicket();
        $this->mw = $this->newTicket(['title' => 'MW ticket', 'actor' => self::ANATOLY], self::NW);

        // id order: 1 tilen input (BH), 2 agent (BH), 3 tilen plain (NW), 4 tilen input (NW), 5 agent (NW)
        $this->tilenSays($this->ca['key'], ['input_choice' => 'Yes', 'body' => 'Yes, ship it'])->assertCreated();
        $this->travelMinutes(10);
        $this->postJson("/api/tickets/{$this->ca['key']}/comments", ['body' => 'Thanks, shipping', 'actor' => self::JANICE])->assertCreated();
        $this->travelMinutes(10);
        $this->tilenSays($this->mw['key'], ['body' => 'Any news?'], 'northwind')->assertCreated();
        $this->travelMinutes(10);
        $this->tilenSays($this->mw['key'], ['body' => 'Use the Q4 numbers', 'is_tilen_input' => true], 'northwind')->assertCreated();
        $this->travelMinutes(10);
        $this->postJson("/api/tickets/{$this->mw['key']}/comments", ['body' => 'Will do', 'actor' => self::ANATOLY])->assertCreated();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ids(string $q = ''): array
    {
        return array_column($this->getJson('/api/ticket-comments/feed'.$q)->assertOk()->json('data'), 'id');
    }

    #[Test]
    public function needs_the_token(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/ticket-comments/feed')->assertUnauthorized();
    }

    #[Test]
    public function defaults_to_tilens_comments_in_id_order_with_context(): void
    {
        $r = $this->getJson('/api/ticket-comments/feed')->assertOk();
        $this->assertSame([1, 3, 4], array_column($r->json('data'), 'id'));
        $this->assertSame(['1', '3', '4'], array_column($r->json('data'), 'cursor'));
        $this->assertSame('4', $r->json('next_cursor'));
        $this->assertFalse($r->json('has_more'));
        $first = $r->json('data.0');
        $this->assertSame([true, 'Yes', 'human', 'tilen', 'Tilen'], [$first['is_tilen_input'], $first['input_choice'], $first['author_type'], $first['author_id'], $first['author_name']]);
        $this->assertSame(['id', 'key', 'title', 'status', 'status_label', 'assignee_type', 'assignee_id', 'assignee_name', 'ask', 'needs_pm_pickup', 'url'], array_keys($first['ticket']));
        $this->assertSame('Waiting on you', $first['ticket']['status_label']);
        $this->assertSame(['id', 'name', 'slug', 'pm_agent_id', 'pm_name'], array_keys($first['project']));
        $this->assertSame('northwind', $r->json('data.1.project.slug'));
        $this->assertSame('Anatoly Korenchkin', $r->json('data.1.project.pm_name'));
    }

    #[Test]
    public function cursor_paging(): void
    {
        $page = $this->getJson('/api/ticket-comments/feed?limit=2')->assertOk();
        $this->assertSame([1, 3], array_column($page->json('data'), 'id'));
        $this->assertTrue($page->json('has_more'));
        $page = $this->getJson('/api/ticket-comments/feed?limit=2&after='.$page->json('next_cursor'))->assertOk();
        $this->assertSame([4], array_column($page->json('data'), 'id'));
        $this->assertFalse($page->json('has_more'));
        // Empty page keeps the cursor so a relay loop can store it unconditionally.
        $page = $this->getJson('/api/ticket-comments/feed?after='.$page->json('next_cursor'))->assertOk();
        $this->assertSame([], $page->json('data'));
        $this->assertSame('4', $page->json('next_cursor'));
        $this->assertNull($this->getJson('/api/ticket-comments/feed?after=99&author=all&since=2026-01-01T00:00:00Z')->json('data.0'));
        $this->getJson('/api/ticket-comments/feed?limit=201')->assertUnprocessable();
        $this->getJson('/api/ticket-comments/feed?after=x')->assertUnprocessable();
    }

    #[Test]
    public function since_and_filters(): void
    {
        $since = urlencode(Carbon::parse('2026-10-29 10:15:00')->toIso8601String());
        $this->assertSame([3, 4], $this->ids("?since=$since"));
        $this->assertSame([4], $this->ids('?since='.urlencode(Carbon::parse('2026-10-29 09:25:00', 'UTC')->toIso8601String()))); // 09:25 UTC = 10:25 Ljubljana (CET)
        $this->assertSame([3, 4], $this->ids("?after=1&since=$since"));
        $this->assertSame([2, 3, 4, 5], $this->ids("?after=1&since=$since&author=all")); // after wins over since
        $this->assertSame([2, 5], $this->ids('?author=agents'));
        $this->assertSame([1, 2, 3, 4, 5], $this->ids('?author=all'));
        $this->assertSame([1, 4], $this->ids('?inputs_only=1'));
        $this->assertSame([3, 4], $this->ids('?project_id='.self::NW));
        $this->assertSame([2], $this->ids('?project_id='.self::BH.'&author=agents'));
        $this->getJson('/api/ticket-comments/feed?author=bots')->assertUnprocessable();

        // Retracted comments drop out of the relay.
        $this->postJson('/api/comments/5/retract', ['actor' => self::ANATOLY])->assertOk();
        $this->assertSame([2], $this->ids('?author=agents'));
    }
}
