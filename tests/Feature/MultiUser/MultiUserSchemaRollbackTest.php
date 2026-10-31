<?php

namespace Tests\Feature\MultiUser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Schema down/up outside a test transaction: SQLite rebuilds tables to drop a foreign key, which needs
 * PRAGMA foreign_keys=off, a no-op inside RefreshDatabase's transaction.
 */
class MultiUserSchemaRollbackTest extends TestCase
{
    use DatabaseMigrations;

    private function migration(string $name): object
    {
        return require database_path("migrations/$name.php");
    }

    #[Test]
    public function schema_down_drops_only_the_added_columns_and_up_restores_them(): void
    {
        $user = User::query()->create(['name' => 'Captain', 'email' => 'captain@example.test', 'password' => Hash::make('x')]);
        $user->createToken('ceo', ['ceo']);
        DB::table('agents')->insert(['id' => 'agent-janice', 'name' => 'Janice', 'role_label' => 'PM']);
        DB::table('projects')->insert(['id' => 'proj-x', 'name' => 'X', 'slug' => 'x', 'owner_agent_id' => 'agent-janice']);
        $keys = $this->migration('2026_10_31_120000_multi_user_workspace_keys');
        $backfill = $this->migration('2026_10_31_110000_multi_user_backfill');
        $schema = $this->migration('2026_10_31_100000_multi_user_schema');

        $keys->down();
        $backfill->down();
        $schema->down();

        $this->assertFalse(Schema::hasColumn('projects', 'workspace_id'));
        $this->assertFalse(Schema::hasColumn('personal_access_tokens', 'workspace_id'));
        $this->assertFalse(Schema::hasColumn('users', 'is_admin'));
        $this->assertFalse(Schema::hasTable('workspaces'));
        $this->assertSame(1, DB::table('projects')->count());
        $this->assertSame(1, DB::table('agents')->count());
        $this->assertSame(1, DB::table('personal_access_tokens')->count());

        $schema->up();
        $backfill->up();
        $keys->up();
        $ws = DB::table('workspaces')->where('slug', config('bridge.default_workspace'))->value('id');
        $this->assertSame(1, DB::table('projects')->where('workspace_id', $ws)->count());
        $this->assertSame(1, DB::table('personal_access_tokens')->where('workspace_id', $ws)->count());
    }

    #[Test]
    public function projects_web_down_refuses_while_data_would_be_lost_and_round_trips_otherwise(): void
    {
        $owner = User::query()->create(['name' => 'Ada Acme', 'email' => 'ada@example.test', 'password' => Hash::make('x')]);
        $ws = DB::table('workspaces')->insertGetId(['slug' => 'acme', 'name' => 'Acme Studio', 'owner_user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('agents')->insert(['id' => 'agent-acme', 'name' => 'Acme PM', 'role_label' => 'PM', 'workspace_id' => $ws]);
        DB::table('projects')->insert(['id' => 'proj-acme', 'name' => 'Acme Studio', 'slug' => 'acme-studio', 'workspace_id' => $ws]);
        $migration = $this->migration('2026_10_31_140000_multi_user_projects_web');

        // A project without a PM, then one with column labels: down() refuses and changes nothing.
        try {
            $migration->down();
            $this->fail('down() should refuse while a project has no PM');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no PM', $e->getMessage());
        }
        DB::table('projects')->update(['owner_agent_id' => 'agent-acme', 'column_labels' => json_encode(['backlog' => 'Ideas'])]);
        try {
            $migration->down();
            $this->fail('down() should refuse while a project has column labels');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('column labels', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('projects', 'column_labels'));

        DB::table('projects')->update(['column_labels' => null]);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('projects', 'column_labels'));
        $this->assertFalse(Schema::hasColumn('projects', 'description'));
        $this->assertSame('agent-acme', DB::table('projects')->value('owner_agent_id'));

        $migration->up();
        DB::table('projects')->insert(['id' => 'proj-acme-2', 'name' => 'Acme Labs', 'slug' => 'acme-labs', 'workspace_id' => $ws]);
        $this->assertSame(2, DB::table('projects')->count());
        $this->assertNotEmpty(DB::select("SELECT * FROM pragma_foreign_key_list('projects') WHERE \"table\" = 'agents'"));
        // DatabaseMigrations rolls everything back after the test; that needs a PM on every project.
        DB::table('projects')->update(['owner_agent_id' => 'agent-acme']);
    }
}
