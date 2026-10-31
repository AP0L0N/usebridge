<?php

namespace Tests\Feature\Screens;

use App\Services\Auth\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Usage page props for the Cursor section: upload permission, Cursor tool, cost when present, "no file" vs zero. */
class UsageCursorScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-31 09:00:00', 'Europe/Ljubljana'));
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function uploadV2(): void
    {
        $csv = (string) file_get_contents(base_path('tests/fixtures/cursor/v2.csv'));
        $this->post('/usage/cursor-csv', ['file' => UploadedFile::fake()->createWithContent('usage.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonStructure(['data' => ['from', 'to', 'rows', 'total_tokens', 'days_imported', 'days_zero', 'days_kept', 'days']]);
    }

    #[Test]
    public function the_owner_sees_the_upload_panel_cursor_tool_cost_and_coverage(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);
        $this->get('/usage?range=30')->assertInertia(fn (Assert $p) => $p
            ->where('cli.cursorUpload', ['allowed' => true, 'lastImportAt' => null])
            ->where('cli.importedTools.2026-10-25', []));

        $this->uploadV2();

        $props = $this->get('/usage?range=30')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Usage/Overview')
            ->where('cli.cursorUpload.allowed', true)
            ->where('cli.cursorUpload.lastImportAt', fn ($at) => $at !== null)
            // covered by the file: marker, so a day without rows would be a true zero; outside it: no file
            ->where('cli.importedTools.2026-10-25', fn ($tools) => in_array('cursor', $tools->all(), true))
            ->where('cli.importedTools.2026-10-20', []))->inertiaProps('cli');

        $cursor = collect($props['tools'])->firstWhere('tool', 'cursor');
        $this->assertSame('Cursor', $cursor['name']);
        $this->assertGreaterThan(0, $cursor['calls']);
        // Cost column present in v2 ($0.05 + 0.02 + …): cents on the tool and its models
        $this->assertArrayHasKey('costCents', $cursor);
        $this->assertEqualsWithDelta($cursor['costCents'], array_sum(array_column($cursor['models'], 'costCents')), 0.0001);
        // one account, never a per-agent split
        foreach (collect($props['table'])->where('tool', 'cursor') as $row) {
            $this->assertSame([], $row['agents']);
        }
        // tools without a cost keep their old shape (no costCents key)
        $this->assertArrayNotHasKey('costCents', collect($props['tools'])->firstWhere('tool', 'claude_code'));
    }

    #[Test]
    public function a_member_cannot_upload_and_gets_no_panel(): void
    {
        $this->actingAs($this->workspaceUser())->get('/usage')->assertInertia(fn (Assert $p) => $p->where('cli.cursorUpload.allowed', false));
        $this->post('/usage/cursor-csv', ['file' => UploadedFile::fake()->createWithContent('usage.csv', 'x')], ['Accept' => 'application/json'])->assertNotFound();
    }

    #[Test]
    public function another_users_usage_page_has_none_of_the_owners_cursor_data(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);
        $this->uploadV2();
        $this->flushSession();

        $sam = app(AccountService::class)->register('Sam Rivera', 'sam@example.test', 'garden-planner-27');
        $props = $this->actingAs($sam)->get('/usage?range=30')->assertInertia(fn (Assert $p) => $p
            ->where('cli.cursorUpload', ['allowed' => true, 'lastImportAt' => null]) // owner of their own workspace
            ->where('cli.importedTools.2026-10-25', []))->inertiaProps('cli');

        $this->assertNull(collect($props['tools'])->firstWhere('tool', 'cursor'));
        $this->assertSame([], $props['table']);
    }
}
