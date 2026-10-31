<?php

namespace Tests\Feature;

use App\Models\UsageDay;
use App\Services\Usage\AgentColors;
use App\Services\Usage\UsageDayImporter;
use App\Services\Usage\UsageReport;
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
        $this->get('/usage')->assertRedirect(route('login'));
        $this->get('/usage/agents/'.self::THE_MANY)->assertRedirect(route('login'));
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
                ->has('series', 19)
                ->where('series.0.name', 'The Many')
                ->where('series.0.agentId', self::THE_MANY)
                ->where('series.9.name', 'Alice')
                ->has('topAgents', 5)
                ->where('topAgents.0.agentId', self::THE_MANY)
                ->where('topAgents.0.tokens', 427362325)
                ->where('topAgents.1.name', 'Shodan')
                ->has('topRoutines', 5)
                ->where('topRoutines.0.routineId', 'tamara-normiranec-updates')
                ->where('unmatched', ['runs' => 150, 'agents' => 17, 'tokens' => 182121379])
                ->has('table.rows', 19)
                ->where('table.totals.tokens', 2625145391)
                ->where('table.totals.turns', 2459)
                ->where('table.rows.1.color', AgentColors::PALETTE[AgentColors::home(self::SHODAN)])
                ->where('table.rows.0.factor', 'median')
                ->where('table.rows.1.factor', 'calibrated'));

        $props = $this->get('/usage')->viewData('page')['props'];
        $this->assertEveryAgentIsASeries($props, self::fixtureRangeTotals());
    }

    /** agent_id => est_tokens_total summed over the 7 fixture files. */
    private static function fixtureRangeTotals(): array
    {
        $totals = [];
        foreach (UsageImportTest::fixturePaths() as $path) {
            foreach (json_decode((string) file_get_contents($path), true)['agents'] as $a) {
                $totals[$a['agent_id']] = ($totals[$a['agent_id']] ?? 0) + $a['est_tokens_total'];
            }
        }

        return $totals;
    }

    /**
     * One series per agent with nonzero range total, ranked desc (then name), its own colour,
     * no "Other" anywhere; every present day's segments (one per series) sum to the day total.
     *
     * @param  array<string, int>  $totals  agent_id => range total
     */
    private function assertEveryAgentIsASeries(array $props, array $totals): void
    {
        $nonzero = array_filter($totals, fn ($t) => $t > 0);
        $series = $props['series'];
        $this->assertCount(count($nonzero), $series);
        $this->assertEqualsCanonicalizing(array_keys($nonzero), array_column($series, 'agentId'));
        foreach ($series as $i => $s) {
            $this->assertSame(['agentId', 'name', 'color', 'total'], array_keys($s));
            $this->assertSame($totals[$s['agentId']], $s['total']);
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $s['color']);
            if ($i > 0) {
                $prev = $series[$i - 1];
                $this->assertTrue($prev['total'] > $s['total'] || ($prev['total'] === $s['total'] && strcmp($prev['name'], $s['name']) < 0));
            }
        }
        if (count($series) <= count(AgentColors::PALETTE)) {
            $this->assertCount(count($series), array_unique(array_column($series, 'color')));
        }
        $this->assertSame($props['rangeTotal'], array_sum(array_column($series, 'total')));
        $this->assertSame(count($nonzero), $props['agentCount']);
        $this->assertCount(UsageReport::TOP_LIST, $props['topAgents']);

        foreach ($props['days'] as $d) {
            if (! $d['present']) {
                $this->assertNull($d['segments']);

                continue;
            }
            $this->assertCount(count($series), $d['segments']);
            $this->assertSame($d['total'], array_sum($d['segments']));
        }

        $json = json_encode($props);
        $this->assertStringNotContainsStringIgnoringCase('Other agents', $json);
        $this->assertStringNotContainsString('"ao"', $json);
    }

    #[Test]
    public function agent_colour_is_the_same_in_both_ranges_and_on_the_detail_page(): void
    {
        $this->loadFixtures();
        $this->unlock();

        $week = array_column($this->get('/usage')->viewData('page')['props']['series'], 'color', 'agentId');
        $month = $this->get('/usage?range=30')->viewData('page')['props'];
        $this->assertSame($week, array_column($month['series'], 'color', 'agentId'));
        foreach (['topAgents', 'topRoutines'] as $list) {
            foreach ($month[$list] as $r) {
                $this->assertSame($week[$r['agentId']], $r['color']);
            }
        }
        foreach ($month['table']['rows'] as $r) {
            $this->assertSame($week[$r['agentId']], $r['color']);
        }
        foreach (array_keys($week) as $id) {
            $this->get('/usage/agents/'.$id)->assertInertia(fn (Assert $page) => $page->where('agent.color', $week[$id]));
            $this->get('/usage/agents/'.$id.'?range=30')->assertInertia(fn (Assert $page) => $page->where('agent.color', $week[$id]));
        }

        // A day where ranks shift (one agent only) keeps everyone's colour.
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'Europe/Ljubljana'));
        $early = array_column($this->get('/usage')->viewData('page')['props']['series'], 'color', 'agentId');
        $this->assertNotSame(array_keys(array_intersect_key($week, $early)), array_keys($early)); // ranks did shift
        $this->assertEquals(array_intersect_key($week, $early), $early);
    }

    #[Test]
    public function agent_with_zero_usage_in_the_range_is_hidden(): void
    {
        $importer = app(UsageDayImporter::class);
        $zeroed = null;
        foreach (UsageImportTest::fixturePaths() as $path) {
            $day = json_decode((string) file_get_contents($path), true);
            $zeroed ??= end($day['agents'])['agent_id'];
            foreach ($day['agents'] as &$a) {
                if ($a['agent_id'] === $zeroed) {
                    $day['est_tokens_total'] -= $a['est_tokens_total'];
                    $a['est_tokens_total'] = 0;
                    $a['est_io_tokens'] = 0;
                    $a['other_est_tokens'] = 0;
                    $a['by_hour'] = array_fill(0, 24, 0);
                    $a['by_routine'] = array_map(fn ($r) => ['est_tokens' => 0] + $r, $a['by_routine']);
                }
            }
            unset($a);
            $importer->import((string) json_encode($day));
        }
        $this->unlock();

        $totals = self::fixtureRangeTotals();
        $totals[$zeroed] = 0;
        $props = $this->get('/usage')->viewData('page')['props'];
        $this->assertEveryAgentIsASeries($props, $totals);
        $this->assertCount(18, $props['series']);
        $this->assertNotContains($zeroed, array_column($props['series'], 'agentId'));
        $this->assertNotContains($zeroed, array_column($props['table']['rows'], 'agentId'));
        $this->assertCount(18, $props['table']['rows']);

        // Its detail page still works, in its usual colour.
        $this->get('/usage/agents/'.$zeroed)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('zero', true)
            ->where('agent.color', $this->colorFor($zeroed)));
    }

    /** The colour an agent gets with the plain fixtures. */
    private function colorFor(string $agentId): string
    {
        $ids = [];
        foreach (UsageImportTest::fixturePaths() as $path) {
            $day = array_column(json_decode((string) file_get_contents($path), true)['agents'], 'agent_id');
            $new = array_values(array_diff($day, $ids));
            sort($new, SORT_STRING);
            array_push($ids, ...$new);
        }

        return AgentColors::assign($ids)[$agentId];
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

        $props = $this->get('/usage?range=30')->viewData('page')['props'];
        $this->assertCount(23, array_filter($props['days'], fn ($d) => ! $d['present']));

        // Same totals as the 7-day range here, so the same series.
        $this->assertEveryAgentIsASeries($props, self::fixtureRangeTotals());
        $week = $this->get('/usage')->viewData('page')['props']['series'];
        $this->assertSame($week, $props['series']);
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
                ->where('agent.color', $this->colorFor(self::THE_MANY))
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
            ->where('routines.2.name', 'No routine (ad-hoc)')
            ->where('routines.3.kind', 'other')
            ->where('routineTotals.wakes', 50));
    }

    #[Test]
    public function old_unmatched_label_is_gone_from_app_and_js(): void
    {
        foreach (['app', 'resources/js'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $this->assertStringNotContainsStringIgnoringCase('Unmatched wakes', file_get_contents($file->getPathname()), $file->getPathname());
                }
            }
        }
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

    /** @return array<string, mixed> the decoded fixture file for one date */
    private static function fixture(string $date): array
    {
        return json_decode((string) file_get_contents(base_path("tests/fixtures/usage/$date.json")), true);
    }

    #[Test]
    public function yesterday_range_covers_only_the_previous_ljubljana_day(): void
    {
        $this->loadFixtures();
        $this->unlock();
        $file = self::fixture('2026-10-18');

        // Expected lists, computed from the fixture alone.
        $agents = $file['agents'];
        usort($agents, fn ($x, $y) => $y['est_tokens_total'] <=> $x['est_tokens_total'] ?: strcmp($x['name'], $y['name']));
        $routines = [];
        $unmatched = ['runs' => 0, 'agents' => [], 'tokens' => 0];
        foreach ($file['agents'] as $a) {
            foreach ($a['by_routine'] as $r) {
                if ($r['routine_id'] === 'unmatched') {
                    $unmatched['runs'] += $r['runs'];
                    $unmatched['tokens'] += $r['est_tokens'];
                    if ($r['runs'] > 0) {
                        $unmatched['agents'][$a['agent_id']] = true;
                    }

                    continue;
                }
                $k = $a['agent_id']."\0".$r['routine_id'];
                $routines[$k] ??= ['agentId' => $a['agent_id'], 'routineId' => $r['routine_id'], 'name' => $r['name'], 'runs' => 0, 'tokens' => 0];
                $routines[$k]['runs'] += $r['runs'];
                $routines[$k]['tokens'] += $r['est_tokens'];
            }
        }
        $routines = array_values($routines);
        usort($routines, fn ($x, $y) => $y['tokens'] <=> $x['tokens'] ?: strcmp($x['name'], $y['name']));
        $nonzero = array_values(array_filter($agents, fn ($a) => $a['est_tokens_total'] > 0));

        $response = $this->get('/usage?range=yesterday')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Usage/Overview')
                ->where('range', 'yesterday')
                ->where('today', '2026-10-19')
                ->where('from', '2026-10-18')
                ->where('to', '2026-10-18')
                ->where('fromLabel', 'Sun 18 Oct')
                ->where('toLabel', 'Sun 18 Oct')
                ->where('empty', false)
                ->where('daysWithFile', 1)
                ->has('days', 1)
                ->where('days.0.date', '2026-10-18')
                ->where('days.0.today', false)
                ->where('days.0.total', $file['est_tokens_total'])
                ->where('rangeTotal', $file['est_tokens_total'])
                ->where('agentCount', count($nonzero))
                ->where('generatedAt', $file['generated_at'])
                ->where('method', $file['method'])
                ->where('unmatched', ['runs' => $unmatched['runs'], 'agents' => count($unmatched['agents']), 'tokens' => $unmatched['tokens']])
                ->where('table.day', '2026-10-18')
                ->where('table.dayMissing', false)
                ->where('table.totals.tokens', $file['est_tokens_total'])
                ->has('table.rows', count($nonzero))
                ->has('table.dailyLabels', 7));

        $props = $response->viewData('page')['props'];
        $this->assertSame(
            array_map(fn ($a) => [$a['agent_id'], $a['est_tokens_total']], array_slice($agents, 0, UsageReport::TOP_LIST)),
            array_map(fn ($a) => [$a['agentId'], $a['tokens']], $props['topAgents']),
        );
        $this->assertSame(
            array_map(fn ($r) => [$r['agentId'], $r['routineId'], $r['runs'], $r['tokens']], array_slice($routines, 0, UsageReport::TOP_LIST)),
            array_map(fn ($r) => [$r['agentId'], $r['routineId'], $r['runs'], $r['tokens']], $props['topRoutines']),
        );
        $this->assertSame(array_column($nonzero, 'est_tokens_total', 'agent_id'), array_column($props['table']['rows'], 'tokens', 'agentId'));
        $this->assertEveryAgentIsASeries($props, array_column($file['agents'], 'est_tokens_total', 'agent_id'));

        // An explicit ?day still wins over the range's own day.
        $this->get('/usage?range=yesterday&day=2026-10-16')->assertInertia(fn (Assert $page) => $page
            ->where('range', 'yesterday')
            ->where('table.day', '2026-10-16')
            ->where('table.totals.tokens', 807261233)
            ->where('rangeTotal', $file['est_tokens_total']));
    }

    #[Test]
    public function yesterday_range_agent_detail_for_the_many(): void
    {
        $this->loadFixtures();
        $this->unlock();
        $file = self::fixture('2026-10-18');
        $mine = collect($file['agents'])->firstWhere('agent_id', self::THE_MANY);
        $this->assertNotNull($mine);
        $ranked = collect($file['agents'])->sortBy([['est_tokens_total', 'desc'], ['name', 'asc']])->pluck('agent_id')->values();

        $response = $this->get('/usage/agents/'.self::THE_MANY.'?range=yesterday')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Usage/Agent')
                ->where('range', 'yesterday')
                ->where('from', '2026-10-18')
                ->where('to', '2026-10-18')
                ->where('hoursFromLabel', 'Sun 18 Oct')
                ->where('stats.tokens', $mine['est_tokens_total'])
                ->where('stats.io', $mine['est_io_tokens'])
                ->where('stats.turns', $mine['turns'])
                ->where('stats.share', $mine['est_tokens_total'] / $file['est_tokens_total'])
                ->where('agent.rank', $ranked->search(self::THE_MANY) + 1)
                ->where('agent.of', count($file['agents']))
                ->has('days', 1)
                ->where('days.0.value', $mine['est_tokens_total'])
                ->has('heat', 1)
                ->where('heat.0.date', '2026-10-18')
                ->where('heat.0.cutoffHour', null)
                ->where('hourTotals', $mine['by_hour'])
                ->where('routineTotals.tokens', $mine['est_tokens_total']));

        $props = $response->viewData('page')['props'];
        $expected = [];
        foreach ($mine['by_routine'] as $r) {
            if ($r['routine_id'] !== 'unmatched') {
                $expected[$r['routine_id']] = [$r['runs'], $r['est_tokens']];
            }
        }
        $got = [];
        foreach ($props['routines'] as $r) {
            if ($r['kind'] === 'routine') {
                $got[$r['routineId']] = [$r['runs'], $r['tokens']];
            }
        }
        $this->assertEquals($expected, $got);
        $this->assertSame($mine['other_est_tokens'], collect($props['routines'])->firstWhere('kind', 'other')['tokens']);
        $this->assertSame($mine['est_tokens_total'], array_sum(array_column($props['routines'], 'tokens')));
    }

    #[Test]
    public function yesterday_range_without_a_file_is_empty_and_ignores_today(): void
    {
        $this->loadFixtures();
        $this->unlock();
        Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'Europe/Ljubljana')); // yesterday = 13 Oct (first fixture)
        $this->get('/usage?range=yesterday')->assertInertia(fn (Assert $page) => $page->where('from', '2026-10-13')->where('empty', false)->has('days', 1));

        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Europe/Ljubljana')); // yesterday = 12 Oct, no file; today has one
        $this->get('/usage?range=yesterday')->assertInertia(fn (Assert $page) => $page
            ->where('from', '2026-10-12')
            ->where('to', '2026-10-12')
            ->where('empty', true)
            ->where('rangeTotal', 0)
            ->where('updatedAt', null)
            ->where('generatedAt', null)
            ->where('days.0.present', false));
    }

    #[Test]
    public function range_values_other_than_7_30_yesterday_fall_back_to_7(): void
    {
        $this->loadFixtures();
        $this->unlock();

        foreach (['1', 'Yesterday', 'YESTERDAY', 'yester day', '7.0', '-yesterday'] as $r) {
            $this->get('/usage?range='.rawurlencode($r))->assertInertia(fn (Assert $page) => $page->where('range', 7)->has('days', 7));
            $this->get('/usage/agents/'.self::THE_MANY.'?range='.rawurlencode($r))->assertInertia(fn (Assert $page) => $page->where('range', 7)->has('days', 7));
        }
        // normalizeRange('yesterday ') is 7 (see UsageYesterdayRangeTest), but over HTTP the global
        // TrimStrings middleware trims query values first, exactly as it already does for "30 ".
        $this->get('/usage?range=yesterday%20')->assertInertia(fn (Assert $page) => $page->where('range', 'yesterday'));
        $this->get('/usage?range=30%20')->assertInertia(fn (Assert $page) => $page->where('range', 30));
        $this->get('/usage?range[]=yesterday')->assertInertia(fn (Assert $page) => $page->where('range', 7)->has('days', 7));
        $this->get('/usage/agents/'.self::THE_MANY.'?range[]=yesterday')->assertInertia(fn (Assert $page) => $page->where('range', 7));
    }
}
