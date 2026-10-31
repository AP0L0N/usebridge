<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/** Board filters "New replies for you" / "Your input not picked up" (Tommy review b5) and their API twins. */
class KanbanReviewFiltersTest extends TestCase
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

    /** @return array{unread: array, pickup: array, plain: array, seen: array} */
    private function fixtures(): array
    {
        $this->api();
        $unread = $this->waitingTicket(['title' => 'Unread reply']);
        $pickup = $this->waitingTicket(['title' => 'Input sent']);
        $plain = $this->newTicket(['title' => 'Nothing new', 'status' => 'in_progress']);
        $seen = $this->waitingTicket(['title' => 'Reply already seen']);

        $this->travelMinutes(1);
        $this->postJson("/api/tickets/{$unread['key']}/comments", ['body' => 'New info', 'actor' => self::JANICE])->assertCreated();
        $this->postJson("/api/tickets/{$plain['key']}/comments", ['body' => 'Internal note', 'actor' => self::JANICE])->assertCreated();
        $this->postJson("/api/tickets/{$seen['key']}/comments", ['body' => 'Old news', 'actor' => self::JANICE])->assertCreated();
        $this->tilenSays($pickup['key'], ['body' => 'Go with yes', 'is_tilen_input' => true])->assertCreated();
        $this->travelMinutes(1);
        $this->asOwner()->postJson("/projects/blue-harbor/tickets/{$seen['key']}/seen")->assertOk();

        return compact('unread', 'pickup', 'plain', 'seen');
    }

    #[Test]
    public function api_lists_only_tickets_with_unread_replies_for_tilen(): void
    {
        $f = $this->fixtures();
        $keys = fn (string $q) => array_column($this->getJson("/api/tickets$q")->assertOk()->json('data'), 'key');

        $this->assertSame([$f['unread']['key']], $keys('?unread_for_tilen=1'));
        $this->assertSame([$f['unread']['key']], $keys('?project_id='.self::BH.'&unread_for_tilen=true'));
        $this->assertSame([$f['unread']['key']], array_column($this->getJson('/api/projects/'.self::BH.'/tickets?unread_for_tilen=1')->json('data'), 'key'));
        $this->assertCount(4, $keys('?unread_for_tilen=0'));
        $this->getJson('/api/tickets?unread_for_tilen=maybe')->assertUnprocessable()->assertJsonValidationErrors('unread_for_tilen');

        // A reply after his last visit makes a seen ticket unread again.
        $this->travelMinutes(1);
        $this->postJson("/api/tickets/{$f['seen']['key']}/comments", ['body' => 'Follow-up', 'actor' => self::ANATOLY])->assertCreated();
        $this->assertEqualsCanonicalizing([$f['unread']['key'], $f['seen']['key']], $keys('?unread_for_tilen=1'));
    }

    #[Test]
    public function api_lists_only_tickets_whose_input_needs_pm_pickup(): void
    {
        $f = $this->fixtures();
        $keys = fn (string $q) => array_column($this->getJson("/api/tickets$q")->assertOk()->json('data'), 'key');

        $this->assertSame([$f['pickup']['key']], $keys('?needs_pm_pickup=1'));
        $this->assertSame([], $keys('?needs_pm_pickup=1&unread_for_tilen=1'));
        $this->postJson("/api/tickets/{$f['pickup']['key']}/ack-input", ['actor' => self::JANICE])->assertOk();
        $this->assertSame([], $keys('?needs_pm_pickup=1'));
    }

    #[Test]
    public function board_props_carry_both_flags_per_ticket(): void
    {
        $f = $this->fixtures();
        $this->asOwner()->get('/projects/blue-harbor')->assertInertia(function (Assert $p) use ($f) {
            $tickets = collect($p->toArray()['props']['board']['columns'])->pluck('tickets')->flatten(1)->keyBy('key');
            $flags = $tickets->map(fn ($t) => [$t['unread_for_tilen'], $t['needs_pm_pickup']]);
            $this->assertSame([true, false], $flags[$f['unread']['key']]);
            $this->assertSame([false, true], $flags[$f['pickup']['key']]);
            $this->assertSame([false, false], $flags[$f['plain']['key']]);
            $this->assertSame([false, false], $flags[$f['seen']['key']]);
        });
    }
}
