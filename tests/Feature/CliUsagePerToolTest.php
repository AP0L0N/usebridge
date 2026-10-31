<?php

namespace Tests\Feature;

use App\Models\CliUsageDay;
use App\Models\CliUsageImport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Usage\CliUsageImporter;
use App\Services\Usage\CliUsageValidator;
use App\Services\Usage\InvalidUsageDay;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Per-tool replace of measured CLI usage: a Cursor file and the collector's file never wipe each other's rows. */
class CliUsagePerToolTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-10-24';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-25 06:00:00', 'Europe/Ljubljana'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private static function file(array $rows, ?array $covered = null, string $date = self::DATE): array
    {
        $file = [
            'date' => $date,
            'timezone' => 'Europe/Ljubljana',
            'generated_at' => $date.'T06:00:00+02:00',
            'source' => 'measured',
            'coverage_from' => '2026-10-12',
            'tools' => $rows,
            'method' => 'test',
        ];

        return $covered === null ? $file : $file + ['covered_tools' => $covered];
    }

    private static function row(string $tool, string $agentId, string $model, int $input, int $calls = 2, array $extra = []): array
    {
        return ['tool' => $tool, 'agent_id' => $agentId, 'name' => ucfirst($agentId), 'model' => $model,
            'input' => $input, 'output' => 10, 'cache_read' => 100, 'cache_creation' => 1, 'calls' => $calls] + $extra;
    }

    /** The collector's file for DATE: one row per legacy tool. */
    private static function collector(): array
    {
        return self::file([
            self::row('claude_code', 'agent-a', 'claude-opus', 1000),
            self::row('kimi_code', 'agent-b', 'kimi-k2', 2000),
            self::row('opencode_go', 'agent-c', 'glm', 3000),
        ]);
    }

    private static function cursor(int $input = 500, ?array $covered = null): array
    {
        return self::file([
            self::row('cursor', 'agent-a', 'claude-4.5-sonnet', $input, 42, ['cost_cents' => 312.5, 'max_mode_calls' => 3]),
            self::row('cursor', 'agent-b', 'gpt-5', $input * 2, 5),
        ], $covered);
    }

    private function import(array $file): array
    {
        return app(CliUsageImporter::class)->importRaw(json_encode($file));
    }

    /** Every stored column of the rows of these tools, in id order. */
    private static function snapshot(array $tools): array
    {
        return DB::table('cli_usage_days')->whereIn('tool', $tools)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private static function markers(?int $workspaceId = null): array
    {
        return CliUsageImport::acrossWorkspaces()
            ->where('workspace_id', $workspaceId ?? app(CurrentWorkspace::class)->default()->id)
            ->orderBy('date')->orderBy('tool')->get()
            ->map(fn ($m) => [substr((string) $m->getRawOriginal('date'), 0, 10), $m->tool, $m->rows, $m->total])->all();
    }

    private function later(): void
    {
        Carbon::setTestNow(Carbon::now()->addHour());
    }

    // ---- importer -----------------------------------------------------------------------------

    #[Test]
    public function a_cursor_upload_adds_cursor_rows_and_leaves_the_collector_rows_byte_for_byte(): void
    {
        $this->import(self::collector());
        $before = self::snapshot(CliUsageDay::LEGACY_COLLECTOR_TOOLS);
        $this->assertCount(3, $before);

        $this->later();
        $r = $this->import(self::cursor());

        $this->assertTrue($r['created']); // first file covering cursor that day
        $this->assertSame(2, $r['rows']);
        $this->assertSame($before, self::snapshot(CliUsageDay::LEGACY_COLLECTOR_TOOLS));
        $this->assertSame(2, CliUsageDay::query()->where('tool', 'cursor')->count());
        $this->assertSame([
            [self::DATE, 'claude_code', 1, 1111],
            [self::DATE, 'cursor', 2, 500 + 111 + 1000 + 111],
            [self::DATE, 'kimi_code', 1, 2111],
            [self::DATE, 'opencode_go', 1, 3111],
        ], self::markers());
    }

    #[Test]
    public function re_posting_cursor_replaces_only_the_cursor_rows(): void
    {
        $this->import(self::collector());
        $this->import(self::cursor());
        $before = self::snapshot(CliUsageDay::LEGACY_COLLECTOR_TOOLS);

        $this->later();
        $r = $this->import(self::file([self::row('cursor', 'agent-z', 'gpt-5', 7)]));

        $this->assertFalse($r['created']);
        $this->assertSame($before, self::snapshot(CliUsageDay::LEGACY_COLLECTOR_TOOLS));
        $cursor = CliUsageDay::query()->where('tool', 'cursor')->get();
        $this->assertSame(['agent-z'], $cursor->pluck('agent_id')->all());
        $this->assertSame(7, $cursor->first()->input_tokens);
        $this->assertContains([self::DATE, 'cursor', 1, 118], self::markers());
    }

    #[Test]
    public function an_empty_cursor_file_with_covered_tools_clears_only_cursor_and_writes_a_zero_marker(): void
    {
        $this->import(self::collector());
        $this->import(self::cursor());
        $before = self::snapshot(CliUsageDay::LEGACY_COLLECTOR_TOOLS);

        $this->later();
        $r = $this->import(self::file([], ['cursor']));

        $this->assertFalse($r['created']);
        $this->assertSame(0, $r['rows']);
        $this->assertSame(0, CliUsageDay::query()->where('tool', 'cursor')->count());
        $this->assertSame($before, self::snapshot(CliUsageDay::LEGACY_COLLECTOR_TOOLS));
        $this->assertContains([self::DATE, 'cursor', 0, 0], self::markers());

        // A first-ever empty Cursor file for another day: zero marker only, nothing else touched.
        $this->assertTrue($this->import(self::file([], ['cursor'], '2026-10-23'))['created']);
        $this->assertSame([['2026-10-23', 'cursor', 0, 0]], array_values(array_filter(self::markers(), fn ($m) => $m[0] === '2026-10-23')));
    }

    #[Test]
    public function the_collector_file_still_replaces_all_three_legacy_tools_and_leaves_cursor(): void
    {
        $this->import(self::collector());
        $this->import(self::cursor());
        $cursor = self::snapshot(['cursor']);

        // Only Claude Code ran: its row is replaced, Kimi and OpenCode are cleared (zero markers), Cursor stays.
        $this->later();
        $r = $this->import(self::file([self::row('claude_code', 'agent-a', 'claude-opus', 9)]));
        $this->assertFalse($r['created']);
        $this->assertSame(['claude_code' => 1], CliUsageDay::query()->whereIn('tool', CliUsageDay::LEGACY_COLLECTOR_TOOLS)->pluck('tool')->countBy()->all());
        $this->assertSame(9, CliUsageDay::query()->where('tool', 'claude_code')->sole()->input_tokens);
        $this->assertSame($cursor, self::snapshot(['cursor']));
        $this->assertContains([self::DATE, 'kimi_code', 0, 0], self::markers());
        $this->assertContains([self::DATE, 'opencode_go', 0, 0], self::markers());

        // An empty collector file clears the three legacy tools, still not Cursor.
        $this->import(self::file([]));
        $this->assertSame(0, CliUsageDay::query()->whereIn('tool', CliUsageDay::LEGACY_COLLECTOR_TOOLS)->count());
        $this->assertSame($cursor, self::snapshot(['cursor']));
    }

    #[Test]
    public function covered_tools_limits_the_replace_to_the_listed_tools(): void
    {
        $this->import(self::collector());
        $kimi = self::snapshot(['kimi_code', 'opencode_go']);

        $this->import(self::file([self::row('claude_code', 'agent-a', 'claude-opus', 5)], ['claude_code']));

        $this->assertSame($kimi, self::snapshot(['kimi_code', 'opencode_go']));
        $this->assertSame(5, CliUsageDay::query()->where('tool', 'claude_code')->sole()->input_tokens);
    }

    #[Test]
    public function a_file_before_coverage_is_still_skipped_untouched(): void
    {
        $this->import(self::collector());
        $before = self::snapshot(CliUsageDay::TOOLS);
        $markers = self::markers();

        $file = ['coverage_from' => '2026-10-25'] + self::file([], ['claude_code', 'cursor']);
        $this->assertSame(CliUsageImporter::SKIP_BEFORE_COVERAGE, $this->import($file)['skipped']);
        $this->assertSame($before, self::snapshot(CliUsageDay::TOOLS));
        $this->assertSame($markers, self::markers());
    }

    #[Test]
    public function cost_cents_and_max_mode_calls_are_stored_and_null_when_absent(): void
    {
        $this->import(self::cursor());
        $this->import(self::collector());

        $sonnet = CliUsageDay::query()->where('model', 'claude-4.5-sonnet')->sole();
        $this->assertSame(312.5, $sonnet->cost_cents);
        $this->assertSame(3, $sonnet->max_mode_calls);
        $gpt = CliUsageDay::query()->where('model', 'gpt-5')->sole();
        $this->assertNull($gpt->cost_cents);
        $this->assertNull($gpt->max_mode_calls);
        $this->assertNull(CliUsageDay::query()->where('tool', 'claude_code')->sole()->cost_cents);

        $this->import(self::file([self::row('cursor', 'agent-a', 'm', 1, 1, ['cost_cents' => 0.0125, 'max_mode_calls' => 0])]));
        $row = CliUsageDay::query()->where('tool', 'cursor')->sole();
        $this->assertSame(0.0125, $row->cost_cents);
        $this->assertSame(0, $row->max_mode_calls);
    }

    #[Test]
    public function an_import_in_workspace_b_never_touches_workspace_a(): void
    {
        $this->import(self::collector());
        $this->import(self::cursor());
        $a = self::snapshot(CliUsageDay::TOOLS);
        $aMarkers = self::markers();

        $owner = User::factory()->create();
        $b = Workspace::query()->create(['slug' => 'other', 'name' => 'Other', 'owner_user_id' => $owner->id]);
        $current = app(CurrentWorkspace::class);
        $current->runAs($b, fn () => $this->import(self::file([self::row('claude_code', 'agent-x', 'claude-opus', 1)])));
        $current->runAs($b, fn () => $this->import(self::file([], ['cursor'])));

        $this->assertSame($a, DB::table('cli_usage_days')->where('workspace_id', $current->default()->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame($aMarkers, self::markers());
        $this->assertSame(1, CliUsageDay::acrossWorkspaces()->where('workspace_id', $b->id)->count());
        $this->assertSame([
            [self::DATE, 'claude_code', 1, 112],
            [self::DATE, 'cursor', 0, 0],
            [self::DATE, 'kimi_code', 0, 0],
            [self::DATE, 'opencode_go', 0, 0],
        ], self::markers($b->id));
    }

    // ---- validator ----------------------------------------------------------------------------

    public static function badFiles(): array
    {
        $cursorRow = fn (array $extra) => self::file([self::row('cursor', 'agent-a', 'm', 1, 4, $extra)]);

        return [
            'row outside covered_tools' => [self::file([self::row('kimi_code', 'agent-a', 'm', 1)], ['cursor']), 'tools.0.tool'],
            'covered_tools not a list' => [self::file([], ['x' => 'cursor']), 'covered_tools'],
            'covered_tools unknown tool' => [self::file([], ['cursor', 'copilot']), 'covered_tools'],
            'covered_tools repeated' => [self::file([], ['cursor', 'cursor']), 'covered_tools'],
            'covered_tools too long' => [self::file([], array_fill(0, 11, 'cursor')), 'covered_tools'],
            'covered_tools not strings' => [self::file([], [1]), 'covered_tools'],
            'cost_cents negative' => [$cursorRow(['cost_cents' => -1]), 'tools.0.cost_cents'],
            'cost_cents too large' => [$cursorRow(['cost_cents' => 1e12 + 1]), 'tools.0.cost_cents'],
            'cost_cents a string' => [$cursorRow(['cost_cents' => '3.5']), 'tools.0.cost_cents'],
            'cost_cents null' => [$cursorRow(['cost_cents' => null]), 'tools.0.cost_cents'],
            'max_mode_calls above calls' => [$cursorRow(['max_mode_calls' => 5]), 'tools.0.max_mode_calls'],
            'max_mode_calls negative' => [$cursorRow(['max_mode_calls' => -1]), 'tools.0.max_mode_calls'],
            'max_mode_calls fractional' => [$cursorRow(['max_mode_calls' => 1.5]), 'tools.0.max_mode_calls'],
        ];
    }

    #[Test]
    #[DataProvider('badFiles')]
    public function invalid_per_tool_fields_are_rejected(array $file, string $path): void
    {
        try {
            (new CliUsageValidator)->validate(json_encode($file));
            $this->fail('expected InvalidUsageDay');
        } catch (InvalidUsageDay $e) {
            $this->assertArrayHasKey($path, $e->errors);
        }
    }

    #[Test]
    public function valid_per_tool_fields_pass(): void
    {
        $v = new CliUsageValidator;
        $v->validate(json_encode(self::cursor(covered: ['cursor'])));
        $v->validate(json_encode(self::file([], [])));
        $v->validate(json_encode(self::file([], CliUsageDay::TOOLS)));
        $v->validate(json_encode(self::file([self::row('cursor', 'a', 'm', 1, 4, ['cost_cents' => 1e12, 'max_mode_calls' => 4])])));
        $this->assertSame(['cursor'], CliUsageImporter::coveredTools(self::cursor()));
        $this->assertSame(CliUsageDay::LEGACY_COLLECTOR_TOOLS, CliUsageImporter::coveredTools(self::file([])));
        $this->assertSame([], CliUsageImporter::coveredTools(self::file([], [])));
        // A file with a cursor row is not the collector's: only the tools in its rows.
        $this->assertSame(['claude_code', 'cursor'], CliUsageImporter::coveredTools(
            self::file([self::row('cursor', 'a', 'm', 1), self::row('claude_code', 'a', 'm', 1)])));
    }

    // ---- POST /api/usage/days (nested cli_usage) ----------------------------------------------

    private function postDay(array $cli): TestResponse
    {
        $day = json_decode(UsageImportTest::fixture(), true);
        $day['cli_usage'] = ['date' => $day['date'], 'generated_at' => $day['date'].'T06:00:00+02:00'] + $cli;
        $token = $this->workspaceUser()->createToken('bridge-api')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->call('POST', '/api/usage/days', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], json_encode($day));
    }

    #[Test]
    public function the_day_endpoint_replaces_per_tool_and_rejects_rows_outside_covered_tools(): void
    {
        $date = json_decode(UsageImportTest::fixture(), true)['date'];
        $this->postDay(self::collector())->assertCreated()->assertJsonPath('data.cli.created', true);
        $legacy = self::snapshot(CliUsageDay::LEGACY_COLLECTOR_TOOLS);
        $this->assertCount(3, $legacy);

        $this->later();
        $this->postDay(self::cursor(covered: ['cursor']))->assertOk()
            ->assertJsonPath('data.cli.created', true)
            ->assertJsonPath('data.cli.rows', 2);
        $this->assertSame($legacy, self::snapshot(CliUsageDay::LEGACY_COLLECTOR_TOOLS));
        $this->assertSame(2, CliUsageDay::query()->where('tool', 'cursor')->count());
        $this->assertSame(312.5, CliUsageDay::query()->where('model', 'claude-4.5-sonnet')->sole()->cost_cents);
        $this->assertSame(['claude_code', 'cursor', 'kimi_code', 'opencode_go'], array_column(self::markers(), 1));
        $this->assertSame([$date], array_unique(array_column(self::markers(), 0)));

        $this->postDay(self::file([self::row('kimi_code', 'agent-a', 'm', 1)], ['cursor']))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['cli_usage.tools.0.tool']]);
        $this->postDay(self::file([], ['cursor', 'cursor']))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['cli_usage.covered_tools']]);
        $this->assertSame(2, CliUsageDay::query()->where('tool', 'cursor')->count());
        $this->assertSame($legacy, self::snapshot(CliUsageDay::LEGACY_COLLECTOR_TOOLS));
    }

    // ---- report + backfill --------------------------------------------------------------------

    #[Test]
    public function the_usage_page_lists_imported_tools_per_date_and_shows_cursor_only_with_rows(): void
    {
        $this->import(self::collector());
        $this->withSession(['bridge_gate_unlocked' => true]);

        $this->get('/usage')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('cli.tools', 3)
            ->where('cli.importedTools.'.self::DATE, CliUsageDay::LEGACY_COLLECTOR_TOOLS)
            ->where('cli.importedTools.2026-10-23', [])
            ->has('cli.importedTools', 7));

        $this->import(self::file([], ['cursor'], '2026-10-23'));
        $this->import(self::cursor());
        $this->get('/usage')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('cli.tools', 4)
            ->where('cli.tools.3.tool', 'cursor')
            ->where('cli.tools.3.name', 'Cursor')
            ->where('cli.tools.3.calls', 47)
            ->where('cli.importedTools.'.self::DATE, CliUsageDay::TOOLS)
            ->where('cli.importedTools.2026-10-23', ['cursor']));
    }

    #[Test]
    public function the_backfill_migration_creates_markers_for_existing_rows_idempotently(): void
    {
        $this->import(self::collector());
        $this->import(self::file([self::row('claude_code', 'agent-a', 'x', 1), self::row('claude_code', 'agent-b', 'y', 2)], null, '2026-10-23'));
        CliUsageImport::acrossWorkspaces()->delete();

        $migration = require database_path('migrations/2026_10_31_130100_cli_usage_imports_backfill.php');
        $migration->up();
        $migration->up();

        // Only tools with rows get one (a legacy empty tool left no trace).
        $this->assertSame([
            ['2026-10-23', 'claude_code', 2, 225],
            [self::DATE, 'claude_code', 1, 1111],
            [self::DATE, 'kimi_code', 1, 2111],
            [self::DATE, 'opencode_go', 1, 3111],
        ], self::markers());
    }
}
