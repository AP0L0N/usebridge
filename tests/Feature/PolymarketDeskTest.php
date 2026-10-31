<?php

namespace Tests\Feature;

use App\Services\Polymarket\PolymarketFeedImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** GET /polymarket: gate, empty state, DeskReport figures from the synthetic feed (DATA-SPEC r1 §3–§5). */
class PolymarketDeskTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $local): void
    {
        Carbon::setTestNow(Carbon::parse($local, 'Europe/Ljubljana'));
    }

    private function unlock(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);
    }

    private function importFeed(?array $snapshot = null, ?array $series = null): void
    {
        app(PolymarketFeedImporter::class)->importBody(PolymarketFeedTest::body($snapshot, $series));
    }

    #[Test]
    public function gate_is_required(): void
    {
        $this->get('/polymarket')->assertRedirect(route('login'));
    }

    #[Test]
    public function no_snapshot_renders_the_feed_unavailable_state(): void
    {
        $this->unlock();
        $this->get('/polymarket')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Polymarket/Desk')
                ->where('feed', null)
                ->has('now'));
    }

    #[Test]
    public function feed_renders_the_desk_figures(): void
    {
        $this->at('2037-10-25 22:00:00');
        $this->importFeed();
        $this->unlock();

        $this->get('/polymarket')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Polymarket/Desk')
                ->where('feed.asOfLabel', 'Today, 20:47')
                ->where('feed.freshness.state', 'fresh')
                ->where('feed.freshness.banner', null)
                ->where('feed.gateway', ['status' => 'ok', 'country' => 'NL', 'geoblock' => false])
                ->where('feed.lastDecision', 'Pass')
                ->where('feed.stop', ['usd' => null, 'sinceLabel' => '6 Oct'])
                ->where('feed.note.tsLabel', 'Today, 19:22')
                ->where('feed.book.value', 1093.3)
                ->where('feed.book.deltaYesterday', 6.62)
                ->where('feed.book.delta7d', -80.96)
                ->where('feed.totalPnl.value', 201.32)
                ->where('feed.totalPnl.fundedSinceLabel', '3 Oct')
                ->where('feed.realized.value', 109.04)
                ->where('feed.unrealized', ['value' => 92.28, 'count' => 8])
                ->where('feed.cash', 189.75)
                ->where('feed.openValue', 903.55)
                ->where('feed.openCount', 8)
                ->where('feed.openCost', 811.27)
                ->where('feed.highWater.value', 1528.56)
                ->where('feed.highWater.setLabel', 'Tue 13 Oct')
                ->where('feed.swept', ['value' => 348.02, 'count' => 8, 'lastLabel' => 'Fri 16 Oct'])
                // chart: from polymarket_series_points, one slot per day 3–25 Oct
                ->has('feed.chart.points', 120)
                ->has('feed.chart.days', 23)
                ->where('feed.chart.days.0', ['date' => '2037-10-03', 'd' => '3', 'wd' => 'Sa', 'mon' => 'Oct', 'weekend' => true, 'today' => false])
                ->where('feed.chart.days.22.today', true)
                ->where('feed.chart.defaultRange', 'all')
                ->where('feed.chart.points.0.min', 9 * 60 + 26)
                ->where('feed.chart.points.0.stop', 562.59)
                ->where('feed.chart.points.119.day', '2037-10-25')
                ->where('feed.chart.points.119.min', 19 * 60 + 22)
                ->where('feed.chart.points.119.cash', 189.75)
                ->where('feed.chart.live.separate', true)
                ->where('feed.chart.live.lastRunTime', '19:22')
                ->where('feed.chart.peak', ['book' => 1528.56, 'label' => 'Tue 13 Oct, 16:13', 'dayLabel' => 'Tue 13 Oct'])
                ->where('feed.chart.stop', ['usd' => 562.59, 'fromLabel' => '3 Oct', 'toLabel' => '6 Oct', 'removed' => true])
                ->has('feed.chart.sweeps', 5)
                ->where('feed.chart.sweeps.3', ['day' => '2037-10-14', 'min' => 10 * 60 + 22, 'total' => 244.03, 'count' => 4])
                // positions
                ->has('feed.positions.open', 8)
                ->has('feed.positions.settled', 3)
                ->where('feed.positions.openTotals.cost', 811.27)
                ->where('feed.positions.openTotals.pnl', 92.28)
                ->where('feed.positions.settledTotals.cost', 403.23)
                ->where('feed.positions.open.2.ends', ['kind' => 'today', 'days' => 0])
                ->where('feed.positions.open.1.ends', ['kind' => 'tomorrow', 'days' => 1])
                ->where('feed.positions.open.4.ends', ['kind' => 'awaiting', 'days' => -4])
                ->where('feed.positions.open.4.url', 'https://polymarket.com/event/lakeshire-independence-referendum-margin-of-victory')
                ->where('feed.positions.settled.0.ends.kind', 'settled')
                // fills: last 7 days, newest first, same-minute fills keep feed order
                ->has('feed.fills.items', 4)
                ->where('feed.fills.items.2.usdc', 2.32)
                ->where('feed.fills.bought', 111.05)
                ->where('feed.fills.noneToday', true)
                ->where('feed.fills.lastFillLabel', 'Thu 22 Oct, 13:41')
                // redeems & sweeps: newest first, ~ times
                ->has('feed.sweeps.items', 13)
                ->where('feed.sweeps.items.0.time', '~08:26')
                ->where('feed.sweeps.items.1.time', '07:58')
                ->where('feed.sweeps.items.3.amount', 19.03)
                ->where('feed.sweeps.items.4.amount', 75)
                ->where('feed.sweeps.items.7.time', '~11:15–13:49')
                ->where('feed.sweeps.redeemed', 1173)
                ->where('feed.sweeps.swept', 348.02)
                ->where('feed.sweeps.recent', false));
    }

    #[Test]
    public function latest_json_shape_renders_degraded_without_guessing(): void
    {
        $this->at('2037-10-25 22:00:00');
        $this->importFeed(PolymarketFeedTest::fixture('latest'), []);
        $this->unlock();

        $this->get('/polymarket')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Polymarket/Desk')
                ->where('feed.book.value', 1093.3)
                ->where('feed.book.deltaYesterday', null)
                ->where('feed.totalPnl.value', null)
                ->where('feed.totalPnl.funded', null)
                ->where('feed.realized.value', null)
                ->where('feed.swept.value', null)
                ->where('feed.gateway', null)
                ->where('feed.lastDecision', null)
                ->where('feed.highWater.value', 1528.56)
                ->where('feed.highWater.setLabel', null)
                ->has('feed.chart.points', 0)
                ->has('feed.positions.open', 8)
                // side / avg_entry / current_value_usd read as outcome / avgPrice / value
                ->where('feed.positions.open.0.outcome', 'Yes')
                ->where('feed.positions.open.0.avgPrice', 0.5155)
                ->where('feed.positions.open.0.value', 8.04)
                ->where('feed.unrealized.value', 108.19)
                ->where('feed.fills.available', true)
                ->has('feed.fills.items', 0)
                ->where('feed.sweeps.available', true)
                ->has('feed.sweeps.items', 0));
    }

    /** Posts are expected weekdays ~19:00; a weekday's post is missed at 21:00 unless a snapshot arrived from 17:00 on. */
    public static function freshness(): array
    {
        $fri = '2037-10-23T19:02+02:00';

        return [
            'Fri post, Fri evening' => [$fri, '2037-10-23 20:00:00', 'fresh', 'Fresh', null],
            'Fri post, Sat midday' => [$fri, '2037-10-24 12:00:00', 'fresh', 'Fresh', null],
            'Fri post, Sun night' => [$fri, '2037-10-25 23:00:00', 'fresh', 'Fresh', null],
            'Fri post, Mon midday' => [$fri, '2037-10-26 12:00:00', 'fresh', 'Fresh', null],
            'Fri post, Mon 19:30' => [$fri, '2037-10-26 19:30:00', 'fresh', 'Fresh', null],
            'Fri post, Mon 20:59' => [$fri, '2037-10-26 20:59:00', 'fresh', 'Fresh', null],
            'Fri post, Mon 21:00' => [$fri, '2037-10-26 21:00:00', 'late', 'Late · missed Mon post', 'Last post Fri 19:02'],
            'Fri post, Tue midday' => [$fri, '2037-10-27 12:00:00', 'late', 'Late · missed Mon post', 'Last post Fri 19:02'],
            'Fri post, Tue 21:30' => [$fri, '2037-10-27 21:30:00', 'stale', 'Stale · 2 posts missed', 'Last post Fri 19:02'],
            'early Mon post, Mon night' => ['2037-10-26T18:30+01:00', '2037-10-26 22:00:00', 'fresh', 'Fresh', null],
            'early Mon post, Tue afternoon' => ['2037-10-26T18:30+01:00', '2037-10-27 15:00:00', 'fresh', 'Fresh', null],
            'Wed snapshot before the window' => ['2037-10-28T16:00+01:00', '2037-10-28 21:00:00', 'late', 'Late · missed Wed post', 'Last post Wed 16:00'],
            // CEST → CET on 25 Oct: the rule is wall-clock, no DST special case.
            'Fri post (CEST), Mon morning (CET)' => [$fri, '2037-10-26 10:00:00', 'fresh', 'Fresh', null],
        ];
    }

    #[Test]
    #[DataProvider('freshness')]
    public function freshness_states(string $asOf, string $now, string $state, string $badge, ?string $key): void
    {
        $snap = PolymarketFeedTest::fixture('feed');
        $snap['as_of'] = $asOf;
        $this->importFeed($snap);
        $this->at($now);
        $this->unlock();

        $this->get('/polymarket')->assertInertia(fn (Assert $page) => $page
            ->where('feed.freshness.state', $state)
            ->where('feed.freshness.badge', $badge)
            ->where($key === null ? 'feed.freshness.banner' : 'feed.freshness.banner.key', $key));
    }

    #[Test]
    public function late_names_the_missed_weekday_post(): void
    {
        $snap = PolymarketFeedTest::fixture('feed');
        $snap['as_of'] = '2037-10-23T19:02+02:00';
        $this->importFeed($snap);
        $this->at('2037-10-26 21:00:00');
        $this->unlock();

        $this->get('/polymarket')->assertInertia(fn (Assert $page) => $page
            ->where('feed.freshness.state', 'late')
            ->where('feed.freshness.badge', 'Late · missed Mon post')
            ->where('feed.freshness.banner.title', 'The Mon ~19:00 post hasn’t arrived.')
            ->where('feed.freshness.banner.body', 'Figures are from Fri 23 Oct, 19:02 and may have moved.')
            ->where('feed.freshness.banner.key', 'Last post Fri 19:02'));
    }

    #[Test]
    public function book_delta_yesterday_is_relative_to_the_snapshot_day(): void
    {
        $snap = PolymarketFeedTest::fixture('feed');
        $snap['as_of'] = '2037-10-26T10:08+01:00';
        $this->importFeed($snap);
        $this->at('2037-10-26 13:10:00');
        $this->unlock();

        $this->get('/polymarket')->assertInertia(fn (Assert $page) => $page
            ->where('feed.asOfLabel', 'Today, 10:08')
            // Δ vs yesterday is relative to as_of's day: the last run on 25 Oct.
            ->where('feed.book.deltaYesterday', 25.46));
    }

    #[Test]
    public function stale_counts_the_missed_weekday_posts(): void
    {
        $snap = PolymarketFeedTest::fixture('feed');
        $snap['as_of'] = '2037-10-23T19:02+02:00';
        $this->importFeed($snap);
        $this->at('2037-10-27 21:30:00');
        $this->unlock();

        $this->get('/polymarket')->assertInertia(fn (Assert $page) => $page
            ->where('feed.freshness.state', 'stale')
            ->where('feed.freshness.badge', 'Stale · 2 posts missed')
            ->where('feed.freshness.banner.title', '2 weekday posts missed.')
            ->where('feed.freshness.banner.body', 'Book value and prices are out of date. Check Captain’s weekday desk routine.'));
    }
}
