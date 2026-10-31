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
        $user = User::query()->create(['name' => 'Captain', 'email' => 'captain@lantis.internal', 'password' => Hash::make('x')]);
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
        $ws = DB::table('workspaces')->where('slug', 'tilen')->value('id');
        $this->assertSame(1, DB::table('projects')->where('workspace_id', $ws)->count());
        $this->assertSame(1, DB::table('personal_access_tokens')->where('workspace_id', $ws)->count());
    }
}
