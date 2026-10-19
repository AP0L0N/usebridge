<?php

namespace Tests\Feature;

use App\Models\UsageDay;
use App\Services\Usage\UsageDayImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UsageViewTest extends TestCase
{
    use RefreshDatabase;

    private const THE_MANY = '79f05054-9a1d-48cc-8fbf-6ba78756b152';

    private const SHODAN = '5b79509e-da30-43eb-a944-a73c98593b18';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-19 16:00:00', 'Europe/Ljubljana'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function loadFixtures(): void
    {
        $importer = app(UsageDayImporter::class);
        foreach (UsageImportTest::fixturePaths() as $path) {
            $importer->import((string) file_get_contents($path));
        }
    }

    private function unlock(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);
    }

    /** @return list<array<string, mixed>> the agent's entries across the 7 fixture files */
    private function fixtureAgentDays(string $agentId): array
    {
        $out = [];
        foreach (UsageImportTest::fixturePaths() as $path) {
            foreach (json_decode((string) file_get_contents($path), true)['agents'] as $a) {
                if ($a['agent_id'] === $agentId) {
                    $out[] = $a;
                }
            }
        }

        return $out;
    }

    #[Test]
    public function gate_is_required(): void
    {
        $this->get('/usage')->assertRedirect(route('gate.show'));
        $this->get('/usage/agents/'.self::THE_MANY)->assertRedirect(route('gate.show'));
    }

    #[Test]
    public function overview_last_7_days(): void
    {
        $this->loadFixtures();
        $this->unlock();

        $this->get('/usage')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Usage/Overview')
                ->where('range', 7)
                ->where('empty', false)
                ->where('rangeTotal', 2625145391)
                ->where('agentCount', 19)
                ->where('daysWithFile', 7)
                ->has('days', 7)
                ->where('days.6.today', true)
                ->where('days.6.label', 'Mon 19')
                ->where('days.3.total', 807261233)
                ->where('updatedAt', '15:29')
                ->where('fromLabel', 'Tue 13 Oct')
                ->where('toLabel', 'Mon 19 Oct')
                ->has('series', 6)
                ->where('series.0.name', 'The Many')
                ->where('series.0.key', 'a1')
                ->where('series.5.name', 'Other agents (14)')
                ->where('topAgents.0.agentId', self::THE_MANY)
                ->where('topAgents.0.tokens', 427362325)
                ->where('topAgents.1.name', 'Shodan')
                ->has('topRoutines', 5)
                ->where('topRoutines.0.routineId', 'tamara-normiranec-updates')
                ->where('unmatched', ['runs' => 150, 'agents' => 17, 'tokens' => 182121379])
                ->has('table.rows', 19)
                ->where('table.totals.tokens', 2625145391)
                ->where('table.totals.turns', 2459)
                ->where('table.rows.1.color', 'a2')
                ->where('table.rows.0.factor', 'median')
                ->where('table.rows.1.factor', 'calibrated'));

        // Day segments add up to the day total (Other = total - top 5).
        $days = $this->get('/usage')->viewData('page')['props']['days'];
        foreach ($days as $d) {
            $this->assertSame($d['total'], array_sum($d['segments']));
        }
    }

    #[Test]
    public function thirty_day_range_has_explicit_missing_days(): void
    {
        $this->loadFixtures();
        $this->unlock();

        $this->get('/usage?range=30')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Usage/Overview')
                ->where('range', 30)
                ->has('days', 30)
                ->where('daysWithFile', 7)
                ->where('rangeTotal', 2625145391)
                ->where('days.0.present', false)
                ->where('days.0.total', null)
                ->where('days.29.present', true)
                ->has('table.rows', 19));

        $days = $this->get('/usage?range=30')->viewData('page')['props']['days'];
        $this->assertCount(23, array_filter($days, fn ($d) => ! $d['present']));
    }

    #[Test]
    public function bad_range_falls_back_to_7(): void
    {
        $this->loadFixtures();
        $this->unlock();

        foreach (['abc', '14', '-1', ''] as $r) {
            $this->get('/usage?range='.$r)->assertInertia(fn (Assert $page) => $page->where('range', 7)->has('days', 7));
        }
        $this->get('/usage?range[]=30')->assertInertia(fn (Assert $page) => $page->where('range', 7));
    }

    #[Test]
    public function day_filter_and_missing_day_notice(): void
    {
        $this->loadFixtures();
        $this->unlock();

        $this->get('/usage?day=2026-10-16')->assertInertia(fn (Assert $page) => $page
            ->where('table.day', '2026-10-16')
            ->where('table.dayMissing', false)
            ->where('table.totals.tokens', 807261233));

        $this->get('/usage?range=30&day=2026-10-10')->assertInertia(fn (Assert $page) => $page
            ->where('table.day', '2026-10-10')
            ->where('table.dayLabel', 'Sat 10 Oct')
            ->where('table.dayMissing', true)
            ->has('table.rows', 0));
    }

    #[Test]
    public function agent_detail_for_the_many(): void
    {
        $this->loadFixtures();
        $this->unlock();

        $days = $this->fixtureAgentDays(self::THE_MANY);
        $this->assertCount(7, $days);
        $hours = array_fill(0, 24, 0);
        $unmatched = ['runs' => 0, 'tokens' => 0];
        foreach ($days as $a) {
            foreach ($a['by_hour'] as $h => $v) {
                $hours[$h] += $v;
            }
            foreach ($a['by_routine'] as $r) {
                if ($r['routine_id'] === 'unmatched') {
                    $unmatched['runs'] += $r['runs'];
                    $unmatched['tokens'] += $r['est_tokens'];
                }
            }
        }
        $other = array_sum(array_column($days, 'other_est_tokens'));

        $response = $this->get('/usage/agents/'.self::THE_MANY)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Usage/Agent')
                ->where('agent.name', 'The Many')
                ->where('agent.rank', 1)
                ->where('agent.of', 19)
                ->where('agent.color', 'a1')
                ->where('agent.factor', 'median')
                ->where('zero', false)
                ->where('stats.tokens', 427362325)
                ->where('stats.tokens', array_sum(array_column($days, 'est_tokens_total')))
                ->where('stats.turns', 381)
                ->where('stats.io', array_sum(array_column($days, 'est_io_tokens')))
                ->where('stats.unmatchedRuns', $unmatched['runs'])
                ->has('days', 7)
                ->has('heat', 7)
                ->where('heat.6.cutoffHour', 15)
                ->where('hourTotals', $hours)
                ->where('routineTotals.tokens', 427362325));

        $props = $response->viewData('page')['props'];
        $byKind = collect($props['routines'])->keyBy('kind');
        $this->assertSame($unmatched['runs'], $byKind['unmatched']['runs']);
        $this->assertSame($unmatched['tokens'], $byKind['unmatched']['tokens']);
        $this->assertSame($other, $byKind['other']['tokens']);
        $this->assertNull($byKind['other']['runs']);
        $this->assertSame('Other: chats, tasks and subagents', $byKind['other']['name']);
        // routines + unmatched + other == est_tokens_total (holds exactly in the fixtures)
        $this->assertSame(427362325, array_sum(array_column($props['routines'], 'tokens')));
        // by_hour may be a token below the total because of rounding, never above
        $this->assertLessThanOrEqual(427362325, array_sum($props['hourTotals']));
        $this->assertGreaterThanOrEqual(427362325 - 7, array_sum($props['hourTotals']));
    }

    #[Test]
    public function agent_detail_shodan_routines_and_calibrated(): void
    {
        $this->loadFixtures();
        $this->unlock();

        $this->get('/usage/agents/'.self::SHODAN)->assertInertia(fn (Assert $page) => $page
            ->component('Usage/Agent')
            ->where('agent.rank', 2)
            ->where('agent.factor', 'calibrated')
            ->where('stats.matchedRuns', 42)
            ->where('stats.unmatchedRuns', 8)
            ->has('routines', 4)
            ->where('routines.0.routineId', 'tamara-normiranec-updates')
            ->where('routines.0.runs', 20)
            ->where('routines.2.kind', 'unmatched')
            ->where('routines.3.kind', 'other')
            ->where('routineTotals.wakes', 50));
    }

    #[Test]
    public function agent_outside_range_shows_zero_state(): void
    {
        $this->loadFixtures();
        $this->unlock();
        Carbon::setTestNow(Carbon::parse('2026-11-30 12:00:00', 'Europe/Ljubljana'));

        $this->get('/usage/agents/'.self::THE_MANY)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Usage/Agent')
            ->where('agent.name', 'The Many')
            ->where('agent.rank', null)
            ->where('zero', true)
            ->where('stats.tokens', 0));
    }

    #[Test]
    public function unknown_agent_is_404(): void
    {
        $this->loadFixtures();
        $this->unlock();

        $this->get('/usage/agents/no-such-agent')->assertNotFound();
        $this->get('/usage/agents/79f05054_9a1d-48cc-8fbf-6ba78756b152')->assertNotFound(); // LIKE "_" is not a match
        $this->get('/usage/agents/'.str_repeat('a', 65))->assertNotFound();
    }

    #[Test]
    public function empty_database_renders_empty_state(): void
    {
        $this->unlock();

        $this->get('/usage')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Usage/Overview')
            ->where('empty', true)
            ->where('daysWithFile', 0)
            ->where('rangeTotal', 0)
            ->where('updatedAt', null)
            ->has('days', 7)
            ->has('table.rows', 0));
        $this->assertSame(0, UsageDay::count());
    }

    #[Test]
    public function unreadable_stored_payload_is_treated_as_missing(): void
    {
        $this->loadFixtures();
        UsageDay::query()->where('date', '2026-10-15')->update(['payload' => '{not json']);
        $this->unlock();

        $this->get('/usage')->assertInertia(fn (Assert $page) => $page
            ->where('daysWithFile', 6)
            ->where('days.2.present', false)
            ->where('unreadable', ['2026-10-15.json']));
    }
}
