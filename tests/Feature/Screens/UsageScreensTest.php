<?php

namespace Tests\Feature\Screens;

use App\Models\CliUsageDay;
use App\Models\User;
use App\Models\UsageDay;
use App\Services\Auth\AccountService;
use App\Services\Usage\CliUsageImporter;
use App\Services\Usage\UsageDayImporter;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Redesigned Usage screens (overview, single agent, empty states): Inertia component + props, own agents only. */
class UsageScreensTest extends TestCase
{
    use RefreshDatabase;

    private const AVA = '11111111-aaaa-4aaa-8aaa-000000000001';

    private const MAX = '22222222-bbbb-4bbb-8bbb-000000000002';

    private User $sam;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2037-10-19 16:00:00', 'Europe/Ljubljana'));
        $this->sam = app(AccountService::class)->register('Sam Rivera', 'sam@example.test', 'garden-planner-27')->fresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** One usage day built from a repo fixture, reduced to two synthetic agents "Ava" and "Max". */
    private function dayPayload(string $path): string
    {
        $day = json_decode((string) file_get_contents($path), true);
        $agents = array_slice($day['agents'], 0, 2);
        foreach ([self::AVA, self::MAX] as $i => $id) {
            $agents[$i]['agent_id'] = $id;
            $agents[$i]['name'] = $i === 0 ? 'Ava' : 'Max';
        }
        $day['agents'] = $agents;
        $day['est_tokens_total'] = array_sum(array_column($agents, 'est_tokens_total'));

        return json_encode($day);
    }

    /** Seeds the 7 fixture days for the signed-in user's workspace, minus the 5th day (a day with no file). */
    private function seedUsage(User $user, bool $skipOne = true): void
    {
        $paths = glob(base_path('tests/fixtures/usage/2037-10-*.json'));
        sort($paths);
        $this->inWorkspace($user, function () use ($paths, $skipOne) {
            foreach ($paths as $i => $path) {
                if ($skipOne && $i === 4) {
                    continue;
                }
                app(UsageDayImporter::class)->import($this->dayPayload($path));
            }
        });
    }

    private function inWorkspace(User $user, callable $fn): mixed
    {
        return app(CurrentWorkspace::class)->runAs($user->homeWorkspace(), $fn);
    }

    private function seedCli(User $user): void
    {
        $this->inWorkspace($user, fn () => app(CliUsageImporter::class)->importRaw((string) file_get_contents(base_path('tests/fixtures/cli/2037-10-24.json'))));
    }

    #[Test]
    public function the_overview_has_chart_ranks_all_agents_and_the_cli_and_cursor_sections(): void
    {
        $this->seedUsage($this->sam);
        $this->seedCli($this->sam);

        $this->actingAs($this->sam)->get('/usage')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Usage/Overview')
            ->where('empty', false)
            ->where('range', 7)
            ->where('agentCount', 2)
            ->where('daysWithFile', 6)
            ->has('days', 7)
            ->where('days.4.present', false)
            ->where('days.4.total', null)
            ->has('series', 2)
            ->where('series.0.name', fn ($n) => in_array($n, ['Ava', 'Max'], true))
            ->has('topAgents', 2)
            ->where('topAgents.0.share', fn ($s) => $s > 0.3 && $s < 1)
            ->has('topRoutines')
            ->has('table.rows', 2)
            ->where('table.rows.0.factor', fn ($f) => in_array($f, ['calibrated', 'median'], true))
            ->has('table.totals.tokens')
            // CLI tools section and Cursor section ride in `cli`
            ->has('cli.tools')
            ->has('cli.days', 7)
            ->where('cli.cursorUpload', ['allowed' => true, 'lastImportAt' => null])
            ->has('updatedAt'));
    }

    #[Test]
    public function the_cursor_section_shows_an_import_for_the_owner_and_the_imported_tool(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 09:00:00', 'Europe/Ljubljana'));
        $this->actingAs($this->sam);
        $csv = (string) file_get_contents(base_path('tests/fixtures/cursor/v2.csv'));
        $this->post('/usage/cursor-csv', ['file' => UploadedFile::fake()->createWithContent('usage.csv', $csv)], ['Accept' => 'application/json'])->assertOk();

        $props = $this->get('/usage?range=30')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Usage/Overview')
            ->where('empty', true)
            ->where('cli.cursorUpload.allowed', true)
            ->where('cli.cursorUpload.lastImportAt', fn ($at) => $at !== null))->inertiaProps('cli');

        $cursor = collect($props['tools'])->firstWhere('tool', 'cursor');
        $this->assertSame('Cursor', $cursor['name']);
        $this->assertGreaterThan(0, count($cursor['models']));
    }

    #[Test]
    public function without_any_usage_the_empty_props_are_returned_and_the_cursor_section_is_empty(): void
    {
        $this->actingAs($this->sam)->get('/usage')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Usage/Overview')
            ->where('empty', true)
            ->where('rangeTotal', 0)
            ->where('agentCount', 0)
            ->where('daysWithFile', 0)
            ->where('topAgents', [])
            ->where('series', [])
            ->where('table.rows', [])
            ->where('cli.tools.0.calls', 0)
            ->where('cli.empty', true)
            ->where('cli.cursorUpload', ['allowed' => true, 'lastImportAt' => null])
            ->where('cli.importedTools.2037-10-19', []));
    }

    #[Test]
    public function the_single_agent_view_has_tiles_by_hour_daily_and_routines(): void
    {
        $this->seedUsage($this->sam);

        $this->actingAs($this->sam)->get('/usage/agents/'.self::AVA)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Usage/Agent')
            ->where('agent.name', 'Ava')
            ->where('agent.id', self::AVA)
            ->where('agent.of', 2)
            ->where('agent.factor', fn ($f) => in_array($f, ['calibrated', 'median'], true))
            ->where('zero', false)
            // tiles: Tokens (est.), Fresh text only (est.), Unprompted wakes + subs
            ->where('stats.tokens', fn ($v) => $v > 0)
            ->where('stats.io', fn ($v) => $v > 0)
            ->has('stats.matchedRuns')
            ->has('stats.unmatchedRuns')
            ->has('stats.turns')
            // by hour (7 x 24 heat rows + 24 hour totals), daily (one entry per day, the missing day is null), routines
            ->has('heat', 7)
            ->has('hourTotals', 24)
            ->has('days', 7)
            ->where('days.4.value', null)
            ->has('routines')
            ->has('routineTotals.tokens'));
    }

    #[Test]
    public function another_users_agent_is_a_404_and_their_usage_never_shows_up(): void
    {
        $this->seedUsage($this->sam);
        $lee = app(AccountService::class)->register('Lee Park', 'lee@example.test', 'orchard-notes-31')->fresh();

        $this->actingAs($lee)->get('/usage/agents/'.self::AVA)->assertNotFound();
        $this->actingAs($lee)->get('/usage')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('empty', true)->where('agentCount', 0)->where('topAgents', []));
    }

    #[Test]
    public function importing_a_day_again_replaces_it_per_date_and_tool(): void
    {
        $this->seedUsage($this->sam, false);
        $this->inWorkspace($this->sam, function () {
            $this->assertSame(7, UsageDay::count());
            app(UsageDayImporter::class)->import($this->dayPayload(base_path('tests/fixtures/usage/2037-10-19.json')));
            $this->assertSame(7, UsageDay::count());
        });

        $this->seedCli($this->sam);
        $rows = $this->inWorkspace($this->sam, fn () => CliUsageDay::count());
        $this->seedCli($this->sam);
        $this->assertSame($rows, $this->inWorkspace($this->sam, fn () => CliUsageDay::count()));
    }
}
