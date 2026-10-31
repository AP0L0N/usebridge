<?php

namespace Tests\Feature;

use App\Models\CliUsageDay;
use App\Models\CliUsageImport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Usage\CliUsageImporter;
use App\Services\Usage\CursorCsvImporter;
use App\Services\Usage\InvalidUsageDay;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Cursor dashboard CSV export (individual plan) → per day × model rows of the single "Cursor (all agents)" account. */
class CursorCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private const V1_HEADER = 'Date,Model,Input (w/ Cache Write),Input (w/o Cache Write),Cache Read,Output Tokens,Total Tokens,Cost,Cost to you';

    private const V2_HEADER = 'Date,Kind,Model,Max Mode,Input (w/ Cache Write),Input (w/o Cache Write),Cache Read,Output Tokens,Total Tokens,Cost';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-31 09:00:00', 'Europe/Ljubljana'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/fixtures/cursor/$name"));
    }

    private function import(string $csv, bool $dryRun = false): array
    {
        return app(CursorCsvImporter::class)->import($csv, $dryRun);
    }

    private function upload(string $csv): TestResponse
    {
        return $this->post('/usage/cursor-csv', ['file' => UploadedFile::fake()->createWithContent('usage.csv', $csv)], ['Accept' => 'application/json']);
    }

    private function tilen(): Workspace
    {
        return app(CurrentWorkspace::class)->default();
    }

    /** Cursor rows of a day in a workspace (default: Tilen's), keyed by model. */
    private function cursorRows(string $date, ?Workspace $ws = null): array
    {
        return CliUsageDay::acrossWorkspaces()
            ->where('workspace_id', ($ws ?? $this->tilen())->id)->where('date', $date)->where('tool', 'cursor')
            ->get()->keyBy('model')->all();
    }

    private function marker(string $date, string $tool = 'cursor', ?Workspace $ws = null): ?CliUsageImport
    {
        return CliUsageImport::acrossWorkspaces()
            ->where('workspace_id', ($ws ?? $this->tilen())->id)->where('date', $date)->where('tool', $tool)->first();
    }

    private function seedCollectorDay(string $date): void
    {
        app(CliUsageImporter::class)->import([
            'date' => $date, 'timezone' => 'Europe/Ljubljana', 'generated_at' => $date.'T06:00:00+02:00',
            'source' => 'measured', 'coverage_from' => '2026-10-01', 'method' => 'test', 'tools' => [
                ['tool' => 'claude_code', 'agent_id' => 'agent-erin', 'name' => 'Erin', 'model' => 'claude-opus', 'input' => 11, 'output' => 22, 'cache_read' => 33, 'cache_creation' => 44, 'calls' => 5],
                ['tool' => 'kimi_code', 'agent_id' => 'agent-kim', 'name' => 'Kim', 'model' => 'kimi-k2', 'input' => 7, 'output' => 8, 'cache_read' => 9, 'cache_creation' => 0, 'calls' => 1],
            ],
        ]);
    }

    private function owner(): User
    {
        return $this->tilen()->users()->wherePivot('role', 'owner')->firstOrFail();
    }

    #[Test]
    public function v1_maps_every_column_and_parses_quoted_thousands_and_cost_variants(): void
    {
        $r = $this->import(self::fixture('v1.csv'));

        $this->assertSame(['2026-10-20', '2026-10-22', 4, 19760, 2, 1, 0], [$r['from'], $r['to'], $r['rows'], $r['total_tokens'], $r['days_imported'], $r['days_zero'], $r['days_kept']]);
        $rows = $this->cursorRows('2026-10-20');
        $this->assertSame(['claude-4.5-sonnet', 'gpt-5'], array_keys($rows));
        $s = $rows['claude-4.5-sonnet'];
        $this->assertSame(['cursor', 'cursor', 'Cursor (all agents)'], [$s->tool, $s->agent_id, $s->agent_name]);
        // input = w/o cache write, cache_creation = w/ cache write (on its own), cache_read, output, calls = rows
        $this->assertSame([3000, 1000, 10000, 350, 2], [$s->input_tokens, $s->cache_creation_tokens, $s->cache_read_tokens, $s->output_tokens, $s->calls]);
        $this->assertEqualsWithDelta(15.0, $s->cost_cents, 0.0001); // $0.11 + 0.04
        $this->assertNull($s->max_mode_calls); // v1 has no Max Mode column
        $this->assertEqualsWithDelta(123450.0, $rows['gpt-5']->cost_cents, 0.0001); // "1,234.5" dollars
        $this->assertEqualsWithDelta(0.0, $this->cursorRows('2026-10-22')['gpt-5']->cost_cents, 0.0001); // "-"
    }

    #[Test]
    public function every_day_between_first_and_last_gets_a_marker_zero_days_included(): void
    {
        $this->import(self::fixture('v1.csv'));

        $zero = $this->marker('2026-10-21');
        $this->assertNotNull($zero);
        $this->assertSame([0, 0], [$zero->rows, $zero->total]);
        $this->assertSame([], $this->cursorRows('2026-10-21'));
        $this->assertNull($this->marker('2026-10-19'));
        $this->assertNull($this->marker('2026-10-23'));
        $this->assertSame(1, $this->marker('2026-10-22')->rows);
        $this->assertSame(0, CliUsageImport::acrossWorkspaces()->where('tool', '!=', 'cursor')->count());
    }

    #[Test]
    public function v2_splits_utc_into_ljubljana_days_across_midnight_and_the_dst_change(): void
    {
        $r = $this->import(self::fixture('v2.csv'));

        $this->assertSame(['2026-10-24', '2026-10-26', 6, 3240], [$r['from'], $r['to'], $r['rows'], $r['total_tokens']]);
        // 21:59Z = 23:59 CEST on the 24th; 22:30Z = 00:30 CEST on the 25th
        $this->assertSame(1, $this->cursorRows('2026-10-24')['claude-4.5-sonnet']->calls);
        $day25 = $this->cursorRows('2026-10-25');
        // 00:59Z = 02:59 CEST, 01:30Z = 02:30 CET (after the change), 22:59Z = 23:59 CET: all still the 25th
        $this->assertSame([3, 900, 10, 1000, 60], [$day25['claude-4.5-sonnet']->calls, $day25['claude-4.5-sonnet']->input_tokens,
            $day25['claude-4.5-sonnet']->cache_creation_tokens, $day25['claude-4.5-sonnet']->cache_read_tokens, $day25['claude-4.5-sonnet']->output_tokens]);
        $this->assertSame(1, $day25['gpt-5']->calls);
        // 23:00Z on the 25th = 00:00 CET on the 26th (UTC+1 now, not +2)
        $this->assertSame([1, 600], [$this->cursorRows('2026-10-26')['gpt-5']->calls, $this->cursorRows('2026-10-26')['gpt-5']->input_tokens]);
    }

    #[Test]
    public function v2_counts_max_mode_yes_or_true_and_errored_or_aborted_rows_still_count(): void
    {
        $this->import(self::fixture('v2.csv'));

        $sonnet = $this->cursorRows('2026-10-25')['claude-4.5-sonnet'];
        $this->assertSame(2, $sonnet->max_mode_calls); // "Yes" + "true"; "No" not
        $this->assertEqualsWithDelta(7.0, $sonnet->cost_cents, 0.0001); // $0.05 + $0.00 + 0.02
        $gpt = $this->cursorRows('2026-10-25')['gpt-5']; // "Aborted, Not Charged", Cost "Free"
        $this->assertSame([1, 500, 0], [$gpt->calls, $gpt->input_tokens, $gpt->max_mode_calls]);
        $this->assertEqualsWithDelta(0.0, $gpt->cost_cents, 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->cursorRows('2026-10-24')['claude-4.5-sonnet']->cost_cents, 0.0001); // "Included"
    }

    #[Test]
    public function v3_ignores_extra_columns_matches_headers_in_any_case_and_order_and_has_no_cost_without_numbers(): void
    {
        $r = $this->import(self::fixture('v3.csv'));

        $this->assertSame([3, 1671], [$r['rows'], $r['total_tokens']]);
        $c = $this->cursorRows('2026-10-27')['composer-1'];
        $this->assertSame([1050, 5, 500, 105, 2, 1], [$c->input_tokens, $c->cache_creation_tokens, $c->cache_read_tokens, $c->output_tokens, $c->calls, $c->max_mode_calls]);
        $this->assertNull($c->cost_cents); // only "Included" / "" in the file
        $this->assertSame('Cursor (all agents)', $c->agent_name);
        $this->assertSame(['auto'], array_keys($this->cursorRows('2026-10-28')));
        $this->assertSame(1, CliUsageDay::acrossWorkspaces()->distinct()->count('agent_id')); // never a per-agent/user split
    }

    #[Test]
    public function a_missing_required_header_is_422_naming_the_missing_and_the_found_headers(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);

        $res = $this->upload("Date,Model,Input,Output Tokens,Cost\n2026-10-20T08:00:00Z,gpt-5,1,2,0\n")->assertStatus(422);
        $msg = $res->json('errors.header.0');
        $this->assertStringContainsString('Input (w/o Cache Write), Input (w/ Cache Write), Cache Read', $msg);
        $this->assertStringContainsString('Found: Date, Model, Input, Output Tokens, Cost', $msg);
        $this->assertSame(0, CliUsageDay::acrossWorkspaces()->count());
    }

    #[Test]
    public function a_malformed_number_is_422_with_its_line_and_nothing_is_written(): void
    {
        $this->import(self::fixture('v2.csv'));
        $before = CliUsageDay::acrossWorkspaces()->orderBy('id')->get()->toArray();
        $markers = CliUsageImport::acrossWorkspaces()->orderBy('date')->get()->toArray();
        $csv = self::V2_HEADER."\n"
            ."2026-10-23T08:00:00Z,Usage-based,gpt-5,No,0,1,0,1,2,$0.01\n"
            ."2026-10-24T08:00:00Z,Usage-based,gpt-5,No,0,1,0,1,2,$0.01\n"
            ."2026-10-25T08:00:00Z,Usage-based,gpt-5,No,0,12x,0,1,2,$0.01\n"
            ."2026-10-26T08:00:00Z,Usage-based,gpt-5,No,0,1,0,1,2,1.2.3\n";
        $this->withSession(['bridge_gate_unlocked' => true]);

        $res = $this->upload($csv)->assertStatus(422);

        $this->assertSame(['Input (w/o Cache Write) (expected a whole number)'], $res->json('errors')['line 4']);
        $this->assertSame(['Cost (expected dollars, e.g. $0.11)'], $res->json('errors')['line 5']);
        $this->assertArrayNotHasKey('line 2', $res->json('errors'));
        $this->assertSame($before, CliUsageDay::acrossWorkspaces()->orderBy('id')->get()->toArray());
        $this->assertSame($markers, CliUsageImport::acrossWorkspaces()->orderBy('date')->get()->toArray());
        $this->assertNull($this->marker('2026-10-23'));
    }

    #[Test]
    public function errors_are_capped_at_twenty_and_bad_dates_are_rejected(): void
    {
        $csv = self::V2_HEADER."\n".str_repeat("2026-10-25 10:00,Usage-based,gpt-5,No,0,1,0,1,2,0\n", 30);

        try {
            $this->import($csv);
            $this->fail('expected InvalidUsageDay');
        } catch (InvalidUsageDay $e) {
            $this->assertCount(20, $e->errors);
            $this->assertSame(['Date (expected an ISO 8601 UTC timestamp)'], $e->errors['line 2']);
        }
        $this->assertSame(0, CliUsageImport::acrossWorkspaces()->count());
    }

    #[Test]
    public function claude_code_kimi_and_opencode_rows_of_the_same_days_are_untouched(): void
    {
        $this->seedCollectorDay('2026-10-20');
        $this->seedCollectorDay('2026-10-21');
        $other = fn () => CliUsageDay::acrossWorkspaces()->where('tool', '!=', 'cursor')->orderBy('id')->get()->toArray();
        $otherMarkers = fn () => CliUsageImport::acrossWorkspaces()->where('tool', '!=', 'cursor')->orderBy('date')->orderBy('tool')->get()->toArray();
        [$rows, $markers] = [$other(), $otherMarkers()];

        $this->import(self::fixture('v1.csv'));
        $this->import(self::fixture('v1.csv'));

        $this->assertCount(4, $rows);
        $this->assertSame($rows, $other());
        $this->assertSame($markers, $otherMarkers());
        $this->assertCount(2, $this->cursorRows('2026-10-20'));
    }

    #[Test]
    public function re_uploading_the_same_file_is_idempotent(): void
    {
        $this->import(self::fixture('v2.csv'));
        $snapshot = fn () => CliUsageDay::acrossWorkspaces()->orderBy('date')->orderBy('model')->get()
            ->map(fn ($r) => $r->only(['date', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_creation_tokens', 'calls', 'cost_cents', 'max_mode_calls']))->all();
        $first = $snapshot();

        $r = $this->import(self::fixture('v2.csv'));

        $this->assertSame($first, $snapshot());
        $this->assertSame(['replaced', 'replaced', 'replaced'], array_column($r['days'], 'state'));
        $this->assertSame(0, $r['days_kept']);
        $this->assertSame(3, CliUsageImport::acrossWorkspaces()->count());
    }

    #[Test]
    public function a_partial_edge_day_with_fewer_calls_is_kept_while_more_calls_or_a_middle_day_replace(): void
    {
        $this->import(self::fixture('v2.csv')); // 24th: 1 call, 25th: 4 calls, 26th: 1 call

        // A later window starting on the 25th late in the day: its first day has 1 call < 4 stored → kept.
        $later = self::V2_HEADER."\n"
            ."2026-10-25T21:00:00Z,Usage-based,gpt-5,No,0,9,0,1,10,0\n"
            ."2026-10-26T08:00:00Z,Usage-based,gpt-5,No,0,7,0,1,8,0\n"
            ."2026-10-26T09:00:00Z,Usage-based,gpt-5,No,0,7,0,1,8,0\n";
        $r = $this->import($later);

        $this->assertSame([CursorCsvImporter::STATE_KEPT, CursorCsvImporter::STATE_REPLACED], array_column($r['days'], 'state'));
        $this->assertSame([4, 1], array_column($r['days'], 'stored_calls'));
        $this->assertSame([1, 0, 1], [$r['days_imported'], $r['days_zero'], $r['days_kept']]);
        $this->assertSame(4, array_sum(array_map(fn ($row) => $row->calls, $this->cursorRows('2026-10-25'))));
        $this->assertSame(2, $this->cursorRows('2026-10-26')['gpt-5']->calls); // 2 >= 1 → replaced

        // The same day in the middle of a window is complete: it replaces even with fewer calls.
        $wide = self::V2_HEADER."\n"
            ."2026-10-24T08:00:00Z,Usage-based,gpt-5,No,0,1,0,1,2,0\n"
            ."2026-10-25T08:00:00Z,Usage-based,gpt-5,No,0,1,0,1,2,0\n"
            ."2026-10-26T08:00:00Z,Usage-based,gpt-5,No,0,1,0,1,2,0\n"
            ."2026-10-26T09:00:00Z,Usage-based,gpt-5,No,0,1,0,1,2,0\n";
        $r = $this->import($wide);
        $this->assertSame(['replaced', 'replaced', 'replaced'], array_column($r['days'], 'state'));
        $this->assertSame(1, array_sum(array_map(fn ($row) => $row->calls, $this->cursorRows('2026-10-25'))));

        // An edge day on a day without a Cursor marker always imports.
        $r = $this->import(self::V2_HEADER."\n2026-10-29T08:00:00Z,Usage-based,gpt-5,No,0,1,0,1,2,0\n");
        $this->assertSame([CursorCsvImporter::STATE_CREATED], array_column($r['days'], 'state'));
    }

    #[Test]
    public function the_gate_session_uploads_into_tilens_workspace_and_gets_a_json_summary(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);

        $this->upload(self::fixture('v1.csv'))->assertOk()
            ->assertJsonPath('data.from', '2026-10-20')->assertJsonPath('data.to', '2026-10-22')
            ->assertJsonPath('data.rows', 4)->assertJsonPath('data.total_tokens', 19760)
            ->assertJsonPath('data.days_imported', 2)->assertJsonPath('data.days_zero', 1)->assertJsonPath('data.days_kept', 0);
        $this->assertCount(2, $this->cursorRows('2026-10-20'));
    }

    #[Test]
    public function the_workspace_owner_may_upload_and_a_member_gets_404(): void
    {
        $member = $this->workspaceUser(['email' => 'member@example.test']);
        $this->actingAs($member, 'web');
        $this->upload(self::fixture('v1.csv'))->assertNotFound();
        $this->assertSame(0, CliUsageDay::acrossWorkspaces()->count());

        $this->actingAs($this->owner(), 'web');
        $this->upload(self::fixture('v1.csv'))->assertOk();
        $this->assertCount(2, $this->cursorRows('2026-10-20'));
    }

    #[Test]
    public function another_workspace_owner_imports_only_into_their_own_workspace(): void
    {
        $this->import(self::fixture('v1.csv')); // Tilen's
        $tilenRows = CliUsageDay::acrossWorkspaces()->where('workspace_id', $this->tilen()->id)->orderBy('id')->get()->toArray();
        $grokOwner = User::factory()->create(['email' => 'grok@example.test']);
        $grok = Workspace::query()->create(['slug' => 'grok', 'name' => 'Grok', 'owner_user_id' => $grokOwner->id]);
        $grok->users()->attach($grokOwner->id, ['role' => 'owner']);

        $this->actingAs($grokOwner, 'web');
        $this->upload(self::fixture('v3.csv'))->assertOk();

        $this->assertSame($tilenRows, CliUsageDay::acrossWorkspaces()->where('workspace_id', $this->tilen()->id)->orderBy('id')->get()->toArray());
        $this->assertSame(['composer-1'], array_keys($this->cursorRows('2026-10-27', $grok)));
        $this->assertSame([], $this->cursorRows('2026-10-27'));
        $this->assertNull($this->marker('2026-10-20', 'cursor', $grok));
        // Grok's owner sees their own workspace's upload state
        $this->get('/usage')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cli.cursorUpload.allowed', true)->whereNot('cli.cursorUpload.lastImportAt', null));
    }

    #[Test]
    public function uploads_are_throttled_to_ten_per_hour(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);
        $csv = self::V2_HEADER."\n2026-10-29T08:00:00Z,Usage-based,gpt-5,No,0,1,0,1,2,0\n";
        for ($i = 0; $i < 10; $i++) {
            $this->upload($csv)->assertOk();
        }

        $this->upload($csv)->assertStatus(429);
    }

    #[Test]
    public function an_upload_needs_a_file(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);

        $this->post('/usage/cursor-csv', [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    #[Test]
    public function usage_page_props_say_who_may_upload_and_cursor_rows_show_as_one_account(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);
        $this->get('/usage')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cli.cursorUpload', ['allowed' => true, 'lastImportAt' => null]));

        $this->import(self::fixture('v3.csv'));
        $this->get('/usage?range=7')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cli.cursorUpload.allowed', true)
            ->where('cli.cursorUpload.lastImportAt', Carbon::now()->toIso8601String())
            ->where('cli.tools.3.tool', 'cursor')
            ->where('cli.tools.3.calls', 3)
            ->where('cli.tools.3.models.0.model', 'composer-1'));

        $this->flushSession();
        $this->actingAs($this->workspaceUser(), 'web');
        $this->get('/usage')->assertOk()->assertInertia(fn (Assert $page) => $page->where('cli.cursorUpload.allowed', false));
    }

    #[Test]
    public function artisan_dry_run_writes_nothing_and_a_real_run_imports(): void
    {
        $path = base_path('tests/fixtures/cursor/v2.csv');

        $this->artisan('bridge:cursor-csv-import', ['path' => $path, '--dry-run' => true])
            ->expectsOutputToContain('DRY RUN (nothing written) '.config('bridge.default_workspace').': 2026-10-24 .. 2026-10-26, 6 rows, 3,240 tokens')
            ->assertExitCode(0);
        $this->assertSame(0, CliUsageDay::acrossWorkspaces()->count());
        $this->assertSame(0, CliUsageImport::acrossWorkspaces()->count());

        $this->artisan('bridge:cursor-csv-import', ['path' => $path, '--workspace' => config('bridge.default_workspace')])
            ->expectsOutputToContain('days imported 3, zero 0, kept 0')->assertExitCode(0);
        $this->assertSame(3, CliUsageImport::acrossWorkspaces()->where('workspace_id', $this->tilen()->id)->count());
    }

    #[Test]
    public function artisan_rejects_an_unknown_workspace_and_an_invalid_file(): void
    {
        $this->artisan('bridge:cursor-csv-import', ['path' => base_path('tests/fixtures/cursor/v1.csv'), '--workspace' => 'nope'])
            ->expectsOutputToContain("workspace 'nope' not found")->assertExitCode(1);
        $this->artisan('bridge:cursor-csv-import', ['path' => base_path('composer.json')])
            ->expectsOutputToContain('invalid, nothing imported')->assertExitCode(1);
        $this->assertSame(0, CliUsageImport::acrossWorkspaces()->count());
    }
}
