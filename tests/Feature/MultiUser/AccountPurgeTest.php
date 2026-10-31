<?php

namespace Tests\Feature\MultiUser;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\AccountPurge;
use App\Services\Auth\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * bridge:accounts-purge (MUST-BUILD A3): behind BRIDGE_ACCOUNT_PURGE_ENABLED, 7 + 30 days after the delete request.
 * Fixtures are generated from the schema (one row in every workspace-owned table and every table that references one),
 * so a new table is covered without touching this test.
 */
class AccountPurgeTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private const FRAMEWORK = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    private User $gone;

    private User $other;

    private Workspace $goneWs;

    private Workspace $otherWs;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        Notification::fake();
        config(['bridge.account_purge_enabled' => true, 'bridge.hibp_enabled' => false]);

        $default = Workspace::query()->where('slug', config('bridge.default_workspace'))->first()
            ?? Workspace::query()->create(['slug' => config('bridge.default_workspace'), 'name' => 'Main', 'features' => []]);
        $this->fill($default->id, 'main');

        [$this->gone, $this->goneWs] = $this->member('Sam Purge', 'sam@example.test');
        [$this->other, $this->otherWs] = $this->member('Kim Stay', 'kim@example.test');
        $this->fill($this->goneWs->id, 'gone', $this->gone);
        $this->fill($this->otherWs->id, 'other', $this->other);
        // Sam is also a plain member of Kim's workspace: the membership goes, Kim's workspace stays.
        $this->otherWs->users()->attach($this->gone->id, ['role' => 'member']);
        $this->deleted($this->gone, 38);
    }

    /** @return array{0: User, 1: Workspace} */
    private function member(string $name, string $email): array
    {
        $user = app(AccountService::class)->register($name, $email, self::PASSWORD);

        return [$user, $user->ownedWorkspaces()->first()];
    }

    private function deleted(User $user, int $daysAgo): void
    {
        $user->forceFill(['delete_requested_at' => Carbon::now()->subDays($daysAgo)])->saveQuietly();
        $user->delete();
    }

    /** Tables with their columns, foreign keys and `<name>_id` references, parents first. */
    private function tables(): array
    {
        $names = array_values(array_diff(Schema::getTableListing(schemaQualified: false), self::FRAMEWORK));
        $info = [];
        foreach ($names as $t) {
            $refs = [];
            foreach (Schema::getForeignKeys($t) as $fk) {
                $refs[$fk['columns'][0]] = $fk['foreign_table'];
            }
            foreach (Schema::getColumns($t) as $c) {
                $target = Str::plural(substr($c['name'], 0, -3));
                if (! isset($refs[$c['name']]) && str_ends_with($c['name'], '_id') && in_array($target, $names, true)) {
                    $refs[$c['name']] = $target;
                }
            }
            $info[$t] = ['columns' => Schema::getColumns($t), 'refs' => $refs];
        }
        $order = [];
        while (count($order) < count($info)) {
            foreach ($info as $t => $i) {
                if (! in_array($t, $order, true) && array_diff(array_diff(array_values($i['refs']), [$t]), $order) === []) {
                    $order[] = $t;
                }
            }
        }

        return array_combine($order, array_map(fn ($t) => $info[$t], $order));
    }

    /**
     * One synthetic row per workspace-owned table (and per table referencing one), plus the user's session, token and
     * password reset. Values come from the column types; references point at this workspace's rows.
     */
    private function fill(int $workspaceId, string $tag, ?User $user = null): void
    {
        $made = ['workspaces' => $workspaceId];
        if ($user) {
            $made['users'] = $user->id;
        }
        foreach ($this->tables() as $table => $info) {
            if (in_array($table, ['users', 'workspaces', 'workspace_user', 'password_reset_tokens'], true)) {
                continue;
            }
            $cols = array_column($info['columns'], 'name');
            $owned = in_array('workspace_id', $cols, true)
                || array_filter($info['refs'], fn ($t) => $t !== 'users' && isset($made[$t])) !== [];
            $userRow = in_array('user_id', $cols, true) && $user !== null;
            if (! $owned && ! $userRow) {
                continue;
            }
            $row = [];
            foreach ($info['columns'] as $c) {
                $name = $c['name'];
                $type = strtolower($c['type_name']);
                if ($c['auto_increment']) {
                    continue;
                }
                if (isset($info['refs'][$name])) {
                    $target = $info['refs'][$name];
                    $value = $made[$target] ?? null;
                    if ($value === null && ! $c['nullable']) {
                        continue 2;
                    }
                    $row[$name] = $value;
                } elseif (str_ends_with($name, '_type') && in_array(substr($name, 0, -5).'_id', $cols, true)) {
                    $row[$name] = User::class;
                } elseif (str_ends_with($name, '_id') && in_array(substr($name, 0, -3).'_type', $cols, true)) {
                    $row[$name] = $user?->id ?? 0;
                } elseif (str_contains($type, 'int') || str_contains($type, 'bool')) {
                    $row[$name] = $name === 'last_activity' ? time() : 1;
                } elseif (preg_match('/dec|num|float|double|real/', $type)) {
                    $row[$name] = 1.5;
                } elseif ($type === 'date') {
                    $row[$name] = '2026-10-01';
                } elseif (str_contains($type, 'time')) {
                    $row[$name] = '2026-10-01 10:00:00';
                } elseif (str_contains($type, 'json')) {
                    $row[$name] = '{}';
                } else {
                    $row[$name] = $name === 'payload' || $name === 'token' ? "$tag-$table-$name" : substr("$tag-$table-$name", 0, 60);
                }
            }
            DB::table($table)->insert($row);
            $made[$table] = $row['id'] ?? DB::table($table)->max('id');
        }
        if ($user) {
            DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'hash', 'created_at' => now()]);
        }
    }

    /** Every row of every table, as JSON strings. */
    private function snapshot(): array
    {
        $all = [];
        foreach (array_keys($this->tables()) as $t) {
            $all[$t] = DB::table($t)->get()->map(fn ($r) => json_encode((array) $r))->sort()->values()->all();
        }

        return $all;
    }

    #[Test]
    public function the_fixtures_cover_every_workspace_owned_table(): void
    {
        foreach ($this->tables() as $t => $info) {
            if (in_array('workspace_id', array_column($info['columns'], 'name'), true)) {
                $this->assertTrue(DB::table($t)->where('workspace_id', $this->goneWs->id)->exists(), "no fixture row in $t");
            }
        }
        foreach (['legacy_r0_project_fields', 'legacy_r0_milestones', 'ticket_label', 'sessions', 'personal_access_tokens', 'cli_usage_imports', 'polymarket_series_points'] as $t) {
            $this->assertGreaterThan(0, DB::table($t)->count(), $t);
        }
    }

    #[Test]
    public function purge_removes_every_row_of_the_user_and_their_workspace_and_nothing_else(): void
    {
        Log::spy();
        $before = $this->snapshot();
        $goneId = $this->gone->id;
        $wsId = $this->goneWs->id;
        $email = $this->gone->email;

        $this->artisan('bridge:accounts-purge')->expectsOutputToContain("user #$goneId purged:")->assertExitCode(0);

        $after = $this->snapshot();
        $tables = $this->tables();
        $deleted = [];
        foreach ($after as $t => $rows) {
            // nothing that stays was changed: every remaining row is byte-identical to before
            $this->assertSame([], array_values(array_diff($rows, $before[$t])), "$t: a remaining row changed");
            $deleted[$t] = array_map(fn ($j) => json_decode($j, true), array_values(array_diff($before[$t], $rows)));
        }

        // every table in the schema: no row references the purged user, workspace or any purged row
        foreach ($after as $t => $rows) {
            foreach ($rows as $json) {
                $row = json_decode($json, true);
                $this->assertStringNotContainsString($email, $json, "$t still holds the email");
                if (array_key_exists('workspace_id', $row)) {
                    $this->assertNotEquals($wsId, $row['workspace_id'], "$t still references the workspace");
                }
                foreach (['user_id', 'owner_user_id'] as $c) {
                    if (array_key_exists($c, $row)) {
                        $this->assertNotEquals($goneId, $row[$c], "$t.$c still references the user");
                    }
                }
                if (($row['tokenable_type'] ?? null) === User::class) {
                    $this->assertNotEquals($goneId, $row['tokenable_id'], "$t still holds a token");
                }
                foreach ($tables[$t]['refs'] as $col => $target) {
                    $gone = array_column($deleted[$target] ?? [], 'id');
                    $this->assertFalse($row[$col] !== null && in_array($row[$col], $gone, false), "$t.$col points at a purged $target row");
                }
            }
        }
        $this->assertEquals([$wsId], array_column($deleted['workspaces'], 'id'));
        $this->assertSame([$goneId], array_column($deleted['users'], 'id'));
        $this->assertNull(DB::table('users')->where('email', $email)->first());

        // only the purged workspace's rows went (plus Sam's membership elsewhere), each workspace table lost its row
        foreach ($deleted as $t => $rows) {
            foreach ($rows as $row) {
                if (array_key_exists('workspace_id', $row) && $t !== 'workspace_user') {
                    $this->assertEquals($wsId, $row['workspace_id'], "$t: a row of another workspace was purged");
                }
            }
            if (in_array('workspace_id', array_column($tables[$t]['columns'], 'name'), true)) {
                $this->assertNotEmpty($rows, "$t: the purged workspace's row was not deleted");
            }
        }
        foreach (['legacy_r0_project_fields', 'ticket_label', 'sessions', 'password_reset_tokens'] as $t) {
            $this->assertNotEmpty($deleted[$t], $t);
        }

        // Kim's workspace: every row byte-identical, members minus Sam
        $this->assertTrue(Workspace::query()->whereKey($this->otherWs->id)->exists());
        $this->assertSame([$this->other->id], DB::table('workspace_user')->where('workspace_id', $this->otherWs->id)->pluck('user_id')->all());

        Log::shouldHaveReceived('info')->withArgs(fn ($m) => str_starts_with($m, "bridge:accounts-purge user #$goneId purged: ")
            && str_contains($m, 'tickets=1') && ! str_contains($m, $email) && ! str_contains($m, 'Sam'))->once();
    }

    #[Test]
    public function the_default_workspace_admins_shared_workspaces_and_recent_requests_are_skipped(): void
    {
        [$owner, $default] = [$this->member('Dora Default', 'dora@example.test')[0], Workspace::query()->where('slug', config('bridge.default_workspace'))->first()];
        $default->forceFill(['owner_user_id' => $owner->id])->save();
        $default->users()->attach($owner->id, ['role' => 'owner']);
        [$admin] = $this->member('Ada Admin', 'ada@example.test');
        $admin->forceFill(['is_admin' => true])->saveQuietly();
        [$sharer, $shared] = $this->member('Pat Share', 'pat@example.test');
        $shared->users()->attach($this->other->id, ['role' => 'member']);
        [$recent] = $this->member('Lee Recent', 'lee@example.test');
        foreach ([$owner, $admin, $sharer] as $u) {
            $this->deleted($u, 40);
        }
        $this->deleted($recent, 36); // 7 + 30 not reached yet
        DB::table('users')->where('id', $this->gone->id)->update(['delete_requested_at' => Carbon::now()->subDay()]); // only the skip cases are due
        $before = $this->snapshot();

        $this->artisan('bridge:accounts-purge')
            ->expectsOutputToContain("user #{$owner->id} skipped: default workspace")
            ->expectsOutputToContain("user #{$admin->id} skipped: admin")
            ->expectsOutputToContain("user #{$sharer->id} skipped: workspace #{$shared->id} has other members")
            ->expectsOutputToContain('Purged 0 user(s), skipped 3.')
            ->assertExitCode(0);

        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function dry_run_changes_nothing(): void
    {
        $before = $this->snapshot();
        $this->artisan('bridge:accounts-purge --dry-run')
            ->expectsOutputToContain("user #{$this->gone->id} would purge: ")
            ->expectsOutputToContain('Dry run, nothing changed: would purge 1 user(s), skipped 0.')
            ->assertExitCode(0);
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function with_the_flag_off_nothing_is_deleted(): void
    {
        config(['bridge.account_purge_enabled' => false]);
        $before = $this->snapshot();
        $this->artisan('bridge:accounts-purge')->expectsOutputToContain('Account purge is disabled')->assertExitCode(0);
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function purge_timing_and_flags_come_from_config(): void
    {
        $this->assertSame(30, config('bridge.account_purge_after_days'));
        $this->assertSame(365, config('bridge.support_email_retention_days'));
        $this->assertTrue(AccountPurge::dueBefore()->equalTo(Carbon::now()->subDays(37)));
        config(['bridge.account_purge_enabled' => false]);
        $this->assertFalse(AccountPurge::enabled());
    }
}
