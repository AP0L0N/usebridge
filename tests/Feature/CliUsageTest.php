<?php

namespace Tests\Feature;

use App\Models\CliUsageDay;
use App\Models\UsageDay;
use App\Models\User;
use App\Services\Usage\CliUsageImporter;
use App\Services\Usage\CliUsageValidator;
use App\Services\Usage\InvalidUsageDay;
use App\Services\Usage\UsageDayImporter;
use App\Services\Usage\UsageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Measured Claude Code / Kimi Code tokens: validator, importer, day-file nesting, artisan backfill, report block. */
class CliUsageTest extends TestCase
{
    use RefreshDatabase;

    private const SHODAN = '5b79509e-da30-43eb-a944-a73c98593b18';

    private const XERXES = 'abbdbd34-ae97-421f-999a-4cb0b28f4587';

    /** 2026-10-24 collector fixture: 3 claude_code rows (Shodan, Xerxes, Unattributed). */
    private const D24_TOTAL = 149178212;

    private const D24_CALLS = 733;

    /** 2026-10-23 collector fixture total. */
    private const D23_TOTAL = 314595434;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function fixture(string $date = '2026-10-24'): string
    {
        return (string) file_get_contents(base_path("tests/fixtures/cli/$date.json"));
    }

    /** @param callable(array): array $mutate */
    private static function mutated(callable $mutate, string $date = '2026-10-24'): string
    {
        return json_encode($mutate(json_decode(self::fixture($date), true)));
    }

    /** A small synthetic collector object. */
    private static function synthetic(string $date, array $tools, string $coverageFrom = '2026-10-12'): array
    {
        return [
            'date' => $date,
            'timezone' => 'Europe/Ljubljana',
            'generated_at' => $date.'T06:00:00+02:00',
            'source' => 'measured',
            'coverage_from' => $coverageFrom,
            'tools' => $tools,
            'method' => 'test',
        ];
    }

    private static function row(string $tool, string $agentId, string $name, int $input, int $output, int $cacheRead, int $cacheCreation, int $calls): array
    {
        return ['tool' => $tool, 'agent_id' => $agentId, 'name' => $name, 'input' => $input, 'output' => $output, 'cache_read' => $cacheRead, 'cache_creation' => $cacheCreation, 'calls' => $calls];
    }

    private function unlock(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);
    }

    private function importFixtures(): void
    {
        $importer = app(CliUsageImporter::class);
        $importer->importRaw(self::fixture('2026-10-23'));
        $importer->importRaw(self::fixture('2026-10-24'));
    }

    // ---- validator ----------------------------------------------------------------------------

    #[Test]
    public function fixtures_validate_and_match_the_collector_schema(): void
    {
        $v = new CliUsageValidator;
        foreach (['2026-10-23', '2026-10-24'] as $date) {
            $doc = $v->validate(self::fixture($date));
            $this->assertSame(CliUsageValidator::TOP_KEYS, array_keys($doc));
            $this->assertSame($date, $doc['date']);
            $this->assertSame('measured', $doc['source']);
            foreach ($doc['tools'] as $row) {
                $this->assertSame(CliUsageValidator::ROW_KEYS, array_keys($row));
            }
        }
        $d24 = $v->validate(self::fixture());
        $this->assertCount(3, $d24['tools']);
        $this->assertSame(self::D24_TOTAL, array_sum(array_map(fn ($r) => $r['input'] + $r['output'] + $r['cache_read'] + $r['cache_creation'], $d24['tools'])));
        $this->assertSame(self::D24_CALLS, array_sum(array_column($d24['tools'], 'calls')));
        $this->assertSame([], $v->validate(json_encode(self::synthetic('2026-10-20', [])))['tools']);
    }

    public static function badCollectorFiles(): array
    {
        return [
            'missing key' => [fn (array $d) => array_diff_key($d, ['coverage_from' => 1]), 'coverage_from'],
            'unknown top key' => [fn (array $d) => $d + ['cost_usd' => 1], 'cost_usd'],
            'source not measured' => [fn (array $d) => ['source' => 'estimate'] + $d, 'source'],
            'bad date' => [fn (array $d) => ['date' => '2026-02-30'] + $d, 'date'],
            'bad coverage_from' => [fn (array $d) => ['coverage_from' => '12.10.2026'] + $d, 'coverage_from'],
            'wrong timezone' => [fn (array $d) => ['timezone' => 'UTC'] + $d, 'timezone'],
            'generated_at without offset' => [fn (array $d) => ['generated_at' => '2026-10-25T06:00:00'] + $d, 'generated_at'],
            'tools not a list' => [fn (array $d) => ['tools' => ['a' => 1]] + $d, 'tools'],
            'unknown row key' => [function (array $d) {
                $d['tools'][0]['model'] = 'x';

                return $d;
            }, 'tools.0.model'],
            'missing row key' => [function (array $d) {
                unset($d['tools'][1]['calls']);

                return $d;
            }, 'tools.1.calls'],
            'unknown tool' => [function (array $d) {
                $d['tools'][0]['tool'] = 'codex';

                return $d;
            }, 'tools.0.tool'],
            'bad agent_id' => [function (array $d) {
                $d['tools'][0]['agent_id'] = 'has space';

                return $d;
            }, 'tools.0.agent_id'],
            'empty name' => [function (array $d) {
                $d['tools'][0]['name'] = '';

                return $d;
            }, 'tools.0.name'],
            'string number' => [function (array $d) {
                $d['tools'][0]['output'] = '12';

                return $d;
            }, 'tools.0.output'],
            'float' => [function (array $d) {
                $d['tools'][0]['cache_read'] = 1.5;

                return $d;
            }, 'tools.0.cache_read'],
            'negative' => [function (array $d) {
                $d['tools'][0]['cache_creation'] = -1;

                return $d;
            }, 'tools.0.cache_creation'],
            'over cap' => [function (array $d) {
                $d['tools'][0]['input'] = 1_000_000_000_000_001;

                return $d;
            }, 'tools.0.input'],
            'zero calls' => [function (array $d) {
                $d['tools'][2]['calls'] = 0;

                return $d;
            }, 'tools.2.calls'],
            'duplicate (tool, agent)' => [function (array $d) {
                $d['tools'][1]['agent_id'] = $d['tools'][0]['agent_id'];

                return $d;
            }, 'tools.1.agent_id'],
        ];
    }

    #[Test]
    #[DataProvider('badCollectorFiles')]
    public function validator_rejects(callable $mutate, string $path): void
    {
        try {
            (new CliUsageValidator)->validate(self::mutated($mutate));
            $this->fail('expected InvalidUsageDay');
        } catch (InvalidUsageDay $e) {
            $this->assertArrayHasKey($path, $e->errors, implode(', ', array_keys($e->errors)));
        }
    }

    #[Test]
    public function validator_rejects_non_object_and_oversized_bodies(): void
    {
        foreach (['[]', '"x"', '{', ''] as $raw) {
            try {
                (new CliUsageValidator)->validate($raw);
                $this->fail('expected InvalidUsageDay');
            } catch (InvalidUsageDay $e) {
                $this->assertArrayHasKey('body', $e->errors);
            }
        }
        try {
            (new CliUsageValidator)->validate(str_repeat(' ', CliUsageValidator::MAX_BYTES + 1));
            $this->fail('expected InvalidUsageDay');
        } catch (InvalidUsageDay $e) {
            $this->assertSame(['body' => ['The file is larger than 512 KB.']], $e->errors);
        }
    }

    // ---- importer -----------------------------------------------------------------------------

    #[Test]
    public function import_is_idempotent_and_replaces_the_day(): void
    {
        $importer = app(CliUsageImporter::class);

        $r = $importer->importRaw(self::fixture());
        $this->assertSame(['date' => '2026-10-24', 'coverage_from' => '2026-10-12', 'rows' => 3, 'total' => self::D24_TOTAL, 'skipped' => null, 'created' => true], $r);
        $this->assertSame(3, CliUsageDay::count());
        $shodan = CliUsageDay::query()->where('agent_id', self::SHODAN)->sole();
        $this->assertSame(['2026-10-24', 'claude_code', 'Shodan', 190, 107525, 16704021, 263248, 95], [
            substr((string) $shodan->getRawOriginal('date'), 0, 10), $shodan->tool, $shodan->agent_name,
            $shodan->input_tokens, $shodan->output_tokens, $shodan->cache_read_tokens, $shodan->cache_creation_tokens, $shodan->calls,
        ]);

        $again = $importer->importRaw(self::fixture());
        $this->assertFalse($again['created']);
        $this->assertSame(3, CliUsageDay::count());
        $this->assertSame(self::D24_TOTAL, (int) CliUsageDay::query()->selectRaw('sum(input_tokens + output_tokens + cache_read_tokens + cache_creation_tokens) as t')->value('t'));

        // Fewer rows for the same date: the old rows go away (replace, not merge).
        $two = self::mutated(function (array $d) {
            array_pop($d['tools']);

            return $d;
        });
        $this->assertSame(2, $importer->importRaw($two)['rows']);
        $this->assertSame(2, CliUsageDay::where('date', '2026-10-24')->count());
        $this->assertSame(0, CliUsageDay::where('agent_id', 'unattributed')->count());
    }

    #[Test]
    public function import_skips_a_day_before_coverage_and_leaves_existing_rows(): void
    {
        $importer = app(CliUsageImporter::class);
        $importer->import(self::synthetic('2026-10-20', [self::row('claude_code', self::XERXES, 'Xerxes', 1, 2, 3, 4, 5)]));
        $this->assertSame(1, CliUsageDay::count());

        $truncated = self::synthetic('2026-10-20', [], '2026-10-21');
        $r = $importer->import($truncated);
        $this->assertSame(CliUsageImporter::SKIP_BEFORE_COVERAGE, $r['skipped']);
        $this->assertSame(1, CliUsageDay::count());
        $this->assertSame(5, CliUsageDay::query()->sole()->calls);

        // Same date as coverage_from is fine.
        $this->assertNull($importer->import(self::synthetic('2026-10-21', [], '2026-10-21'))['skipped']);
    }

    #[Test]
    public function dry_run_validates_and_writes_nothing(): void
    {
        $r = app(CliUsageImporter::class)->importRaw(self::fixture(), true);
        $this->assertSame(['rows' => 3, 'created' => true], ['rows' => $r['rows'], 'created' => $r['created']]);
        $this->assertSame(0, CliUsageDay::count());
    }

    // ---- day files with cli_usage ------------------------------------------------------------

    /** The 2026-10-19 estimator fixture carrying a collector object (the 24 Oct fixture re-dated). */
    private static function dayWithCli(?callable $mutateCli = null, mixed $cliOverride = false): string
    {
        $day = json_decode(UsageImportTest::fixture(), true);
        $cli = json_decode(self::fixture(), true);
        $cli['date'] = '2026-10-19';
        if ($mutateCli !== null) {
            $cli = $mutateCli($cli);
        }
        $day['cli_usage'] = $cliOverride === false ? $cli : $cliOverride;

        return json_encode($day);
    }

    private function postDay(string $body): \Illuminate\Testing\TestResponse
    {
        $token = User::factory()->create()->createToken('bridge-api')->plainTextToken;

        return $this->call('POST', '/api/usage/days', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], $body);
    }

    #[Test]
    public function day_file_with_cli_usage_imports_both_and_strips_it_from_the_payload(): void
    {
        $this->postDay(self::dayWithCli())
            ->assertCreated()
            ->assertJsonPath('data.date', '2026-10-19')
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.cli.rows', 3)
            ->assertJsonPath('data.cli.total', self::D24_TOTAL)
            ->assertJsonPath('data.cli.skipped', null);

        $row = UsageDay::query()->sole();
        $this->assertArrayNotHasKey('cli_usage', $row->payload);
        $this->assertSame(json_decode(UsageImportTest::fixture(), true), $row->payload);
        $this->assertSame(3, CliUsageDay::where('date', '2026-10-19')->count());

        // Re-posting replaces both.
        $this->postDay(self::dayWithCli())->assertOk()->assertJsonPath('data.cli.created', false);
        $this->assertSame(1, UsageDay::count());
        $this->assertSame(3, CliUsageDay::count());
    }

    #[Test]
    public function day_file_with_null_or_without_cli_usage_imports_as_before(): void
    {
        $this->postDay(self::dayWithCli(null, null))
            ->assertCreated()
            ->assertExactJson(['data' => ['date' => '2026-10-19', 'est_tokens_total' => 176291252, 'agents' => 19, 'created' => true]]);
        $this->assertArrayNotHasKey('cli_usage', UsageDay::query()->sole()->payload);
        $this->assertSame(0, CliUsageDay::count());

        $this->postDay(UsageImportTest::fixture())->assertOk()->assertJsonMissingPath('data.cli');
        $this->assertSame(0, CliUsageDay::count());
    }

    #[Test]
    public function day_file_cli_usage_before_coverage_is_skipped_not_rejected(): void
    {
        $this->postDay(self::dayWithCli(fn (array $c) => ['coverage_from' => '2026-10-20'] + $c))
            ->assertCreated()
            ->assertJsonPath('data.cli.skipped', CliUsageImporter::SKIP_BEFORE_COVERAGE);
        $this->assertSame(1, UsageDay::count());
        $this->assertSame(0, CliUsageDay::count());
    }

    public static function badNestedCli(): array
    {
        return [
            'date differs from the day' => [fn (array $c) => ['date' => '2026-10-24'] + $c, 'cli_usage.date'],
            'unknown key' => [fn (array $c) => $c + ['extra' => 1], 'cli_usage.extra'],
            'bad row' => [function (array $c) {
                $c['tools'][0]['calls'] = 'many';

                return $c;
            }, 'cli_usage.tools.0.calls'],
        ];
    }

    #[Test]
    #[DataProvider('badNestedCli')]
    public function invalid_nested_cli_usage_rejects_the_whole_file(callable $mutate, string $path): void
    {
        $this->postDay(self::dayWithCli($mutate))->assertStatus(422)->assertJsonStructure(['errors' => [$path]]);
        $this->assertSame(0, UsageDay::count());
        $this->assertSame(0, CliUsageDay::count());
    }

    #[Test]
    public function nested_cli_usage_must_be_null_or_an_object(): void
    {
        foreach ([[], 'x', 1, [1, 2]] as $bad) {
            $this->postDay(self::dayWithCli(null, $bad))->assertStatus(422);
        }
        $this->assertSame(0, UsageDay::count());
    }

    // ---- artisan ------------------------------------------------------------------------------

    #[Test]
    public function artisan_cli_usage_import_creates_replaces_skips_and_dry_runs(): void
    {
        $p23 = base_path('tests/fixtures/cli/2026-10-23.json');
        $p24 = base_path('tests/fixtures/cli/2026-10-24.json');

        $this->artisan('bridge:cli-usage-import', ['paths' => [$p24], '--dry-run' => true])
            ->expectsOutputToContain('2026-10-24  valid, would be created  total 149,178,212 measured  (3 rows)')
            ->assertExitCode(0);
        $this->assertSame(0, CliUsageDay::count());

        $this->artisan('bridge:cli-usage-import', ['paths' => [$p23, $p24]])
            ->expectsOutputToContain('2026-10-23  created  total 314,595,434 measured')
            ->expectsOutputToContain('2026-10-24  created  total 149,178,212 measured  (3 rows)')
            ->assertExitCode(0);
        $this->assertSame(2, CliUsageDay::query()->distinct()->count('date'));

        $this->artisan('bridge:cli-usage-import', ['paths' => [$p24]])
            ->expectsOutputToContain('2026-10-24  replaced')
            ->assertExitCode(0);

        $tmp = tempnam(sys_get_temp_dir(), 'cli');
        file_put_contents($tmp, json_encode(self::synthetic('2026-10-10', [], '2026-10-12')));
        $this->artisan('bridge:cli-usage-import', ['paths' => [$tmp]])
            ->expectsOutputToContain('2026-10-10  skipped: before coverage (coverage from 2026-10-12')
            ->assertExitCode(0);

        file_put_contents($tmp, '{"date":"2026-10-24"}');
        $this->artisan('bridge:cli-usage-import', ['paths' => [$tmp, '/nonexistent.json']])
            ->expectsOutputToContain("$tmp: invalid")
            ->expectsOutputToContain('/nonexistent.json: not a readable file')
            ->assertExitCode(1);
        unlink($tmp);
    }

    // ---- report -------------------------------------------------------------------------------

    #[Test]
    public function overview_carries_the_measured_block_for_7_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 16:00:00', 'Europe/Ljubljana'));
        $this->importFixtures();
        $this->unlock();

        $this->get('/usage')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Usage/Overview')
            ->where('range', 7)
            ->where('empty', true) // no estimator files at all: the measured block is independent of them
            ->where('cli.empty', false)
            ->where('cli.coverageFrom', '2026-10-23')
            ->where('cli.coverageFromLabel', 'Fri 23 Oct')
            ->where('cli.daysWithRows', 2)
            ->where('cli.total', self::D23_TOTAL + self::D24_TOTAL)
            ->has('cli.tools', 2)
            ->where('cli.tools.0.tool', 'claude_code')
            ->where('cli.tools.0.name', 'Claude Code')
            ->where('cli.tools.0.color', UsageReport::TOOL_COLORS['claude_code'])
            ->where('cli.tools.0.total', self::D23_TOTAL + self::D24_TOTAL)
            ->where('cli.tools.0.days', 2)
            ->where('cli.tools.1.tool', 'kimi_code')
            ->where('cli.tools.1.calls', 0)
            ->where('cli.tools.1.total', 0)
            ->has('cli.series', 1)
            ->where('cli.series.0.agentId', 'claude_code')
            ->has('cli.days', 7)
            ->where('cli.days.0.date', '2026-10-19')
            ->where('cli.days.0.present', false) // before coverage: no data, not zero
            ->where('cli.days.0.total', null)
            ->where('cli.days.4.date', '2026-10-23')
            ->where('cli.days.4.present', true)
            ->where('cli.days.4.total', self::D23_TOTAL)
            ->where('cli.days.4.segments', [self::D23_TOTAL])
            ->where('cli.days.5.total', self::D24_TOTAL)
            ->where('cli.days.6.date', '2026-10-25')
            ->where('cli.days.6.today', true)
            ->where('cli.days.6.present', false) // after the last imported day: not collected yet
            ->has('cli.table', 2)
            ->where('cli.table.1.date', '2026-10-24')
            ->where('cli.table.1.tool', 'claude_code')
            ->where('cli.table.1.total', self::D24_TOTAL)
            ->where('cli.table.1.calls', self::D24_CALLS)
            ->has('cli.table.1.agents', 3)
            ->where('cli.table.1.agents.0.agentId', self::SHODAN)
            ->where('cli.table.1.agents.0.total', 190 + 107525 + 16704021 + 263248));

        $tools = $this->get('/usage')->viewData('page')['props']['cli']['tools'][0];
        $this->assertSame($tools['input'] + $tools['output'] + $tools['cacheRead'] + $tools['cacheCreation'], $tools['total']);
        $this->assertSame(['tool', 'name', 'color', 'input', 'output', 'cacheRead', 'cacheCreation', 'total', 'calls', 'days'], array_keys($tools));
    }

    #[Test]
    public function overview_yesterday_and_30_day_ranges_scope_the_measured_block(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 00:30:00', 'Europe/Ljubljana')); // 22:30 UTC on 24 Oct: still yesterday = 24 Oct
        $this->importFixtures();
        $this->unlock();

        $this->get('/usage?range=yesterday')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('range', 'yesterday')
            ->where('from', '2026-10-24')
            ->where('cli.empty', false)
            ->where('cli.total', self::D24_TOTAL)
            ->where('cli.calls', self::D24_CALLS)
            ->has('cli.days', 1)
            ->where('cli.days.0.date', '2026-10-24')
            ->has('cli.table', 1)
            ->where('cli.tools.0.days', 1));

        $this->get('/usage?range=30')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('range', 30)
            ->has('cli.days', 30)
            ->where('cli.total', self::D23_TOTAL + self::D24_TOTAL)
            ->where('cli.days.29.date', '2026-10-25')
            ->where('cli.days.28.total', self::D24_TOTAL));
    }

    #[Test]
    public function measured_block_is_empty_outside_the_rows_and_zero_days_inside_coverage_are_present(): void
    {
        $importer = app(CliUsageImporter::class);
        $importer->import(self::synthetic('2026-10-20', [
            self::row('claude_code', self::XERXES, 'Xerxes', 10, 20, 30, 40, 2),
            self::row('kimi_code', self::SHODAN, 'Shodan', 1, 2, 3, 4, 1),
            self::row('kimi_code', 'unattributed', 'Unattributed', 1, 1, 1, 1, 1),
        ]));
        $importer->import(self::synthetic('2026-10-22', [self::row('kimi_code', self::SHODAN, 'Shodan', 5, 5, 5, 5, 3)]));
        $this->unlock();

        Carbon::setTestNow(Carbon::parse('2026-10-23 12:00:00', 'Europe/Ljubljana'));
        $this->get('/usage')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cli.empty', false)
            ->where('cli.total', 100 + 10 + 4 + 20)
            ->where('cli.calls', 7)
            ->has('cli.series', 2)
            ->where('cli.series.0.name', 'Claude Code')
            ->where('cli.series.1.name', 'Kimi Code')
            ->where('cli.days.3.date', '2026-10-20')
            ->where('cli.days.3.segments', [100, 14])
            ->where('cli.days.4.date', '2026-10-21')
            ->where('cli.days.4.present', true) // inside coverage, no rows: a true zero
            ->where('cli.days.4.total', 0)
            ->where('cli.days.4.segments', [0, 0])
            ->where('cli.days.5.segments', [0, 20])
            ->where('cli.days.6.present', false)
            ->has('cli.table', 3)
            ->where('cli.table.0.tool', 'claude_code')
            ->where('cli.table.0.agents', []) // a single agent: no breakdown
            ->where('cli.table.1.tool', 'kimi_code')
            ->has('cli.table.1.agents', 2)
            ->where('cli.table.1.agents.1.name', 'Unattributed'));

        Carbon::setTestNow(Carbon::parse('2026-12-01 12:00:00', 'Europe/Ljubljana'));
        $this->get('/usage')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cli.empty', true)
            ->where('cli.coverageFrom', '2026-10-20')
            ->where('cli.total', 0)
            ->where('cli.series', [])
            ->where('cli.table', [])
            ->has('cli.days', 7)
            ->where('cli.days.0.present', false));
    }
}
