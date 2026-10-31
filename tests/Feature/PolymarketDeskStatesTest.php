<?php

namespace Tests\Feature;

use App\Models\PolymarketSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Polymarket\PolymarketFeedImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The redesigned desk (Polymarket/Desk): owner-only, and its default / stale / unavailable / no-positions states. Synthetic feed only. */
class PolymarketDeskStatesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@example.test')->firstOrFail();
    }

    private function import(array $over = []): void
    {
        $snap = array_merge(PolymarketFeedTest::fixture('feed'), [
            'positions' => [[
                'market' => 'Will it rain in Testville on 1 Nov?', 'event_slug' => 'rain-in-testville', 'outcome' => 'Yes', 'shares' => 220,
                'avg_price' => 0.41, 'cost_usd' => 90.2, 'cur_price' => 0.58, 'value_usd' => 127.6, 'pnl_usd' => 37.4,
                'end_date' => '2037-11-01', 'redeemable' => false, 'settled' => false,
            ]],
            'open_count' => 1,
        ], $over);
        app(PolymarketFeedImporter::class)->importBody(PolymarketFeedTest::body($snap));
    }

    #[Test]
    public function non_owners_and_other_users_get_404(): void
    {
        $this->import();
        $member = $this->workspaceUser(['email' => 'member@example.test']);
        $stranger = User::factory()->create(['email' => 'alex@example.test']);
        Workspace::query()->create(['slug' => 'alex', 'name' => 'Alex', 'owner_user_id' => $stranger->id, 'features' => []]);
        foreach ([$member, $stranger] as $user) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($user)->get('/polymarket')->assertNotFound();
        }
    }

    #[Test]
    public function owner_gets_the_desk_with_the_props_the_page_uses(): void
    {
        Carbon::setTestNow(Carbon::parse('2037-10-25 22:00:00', 'Europe/Ljubljana'));
        $this->import();

        $this->actingAs($this->owner())->get('/polymarket')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Polymarket/Desk')
            ->has('now')
            ->has('feed', fn (Assert $f) => $f
                ->has('asOfLabel')->has('asOfTime')->has('freshness')->has('gateway')->has('lastDecision')->has('stop')->has('note')
                ->has('book')->has('totalPnl')->has('realized')->has('unrealized')->has('cash')->has('openValue')->has('openCount')
                ->has('openCost')->has('highWater')->has('swept')->has('chart')->has('positions')->has('fills')->has('sweeps')
                ->has('costBasis')->has('asOf')->has('receivedAt')
                ->where('freshness.state', 'fresh')
                ->where('positions.open.0.market', 'Will it rain in Testville on 1 Nov?')
                ->where('positions.open.0.url', 'https://polymarket.com/event/rain-in-testville')));
    }

    #[Test]
    public function stale_feed_carries_the_translated_banner(): void
    {
        $this->import(['as_of' => '2037-10-23T19:02+02:00']);
        Carbon::setTestNow(Carbon::parse('2037-10-27 21:30:00', 'Europe/Ljubljana'));

        $this->actingAs($this->owner())->get('/polymarket')->assertInertia(fn (Assert $p) => $p
            ->where('feed.freshness.state', 'stale')
            ->where('feed.freshness.badge', __('pm.stale.badge', ['n' => 2]))
            ->where('feed.freshness.banner.title', __('pm.stale.title', ['n' => 2]))
            ->where('feed.freshness.banner.body', __('pm.stale.body'))
            ->has('feed.positions.open', 1));
    }

    #[Test]
    public function unavailable_feed_is_null_for_a_missing_or_unreadable_snapshot(): void
    {
        $this->actingAs($this->owner())->get('/polymarket')->assertInertia(fn (Assert $p) => $p->component('Polymarket/Desk')->where('feed', null));

        $this->import();
        PolymarketSnapshot::query()->update(['payload' => ['as_of' => 'not a snapshot']]);
        $this->actingAs($this->owner())->get('/polymarket')->assertInertia(fn (Assert $p) => $p->component('Polymarket/Desk')->where('feed', null));
    }

    #[Test]
    public function no_positions_state_keeps_the_page_and_shows_realized_as_not_supplied(): void
    {
        Carbon::setTestNow(Carbon::parse('2037-10-25 22:00:00', 'Europe/Ljubljana'));
        $this->import(['positions' => [], 'open_count' => 0, 'open_value_usd' => 0.0, 'realized_pnl_usd' => null]);

        $this->actingAs($this->owner())->get('/polymarket')->assertInertia(fn (Assert $p) => $p
            ->where('feed.openCount', 0)
            ->has('feed.positions.open', 0)
            ->has('feed.positions.settled', 0)
            ->where('feed.positions.openTotals.value', null)
            ->where('feed.realized.value', null)
            ->where('feed.unrealized.value', 0));
    }
}
