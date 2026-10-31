<?php

namespace Tests\Feature\MultiUser;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/** Backfill + per-workspace keys migrations: idempotent, reversible, nothing deleted. Raw rows only (no model scopes). */
class MultiUserMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $name): object
    {
        return require database_path("migrations/$name.php");
    }

    private function backfill(): object
    {
        return $this->migration('2026_10_31_110000_multi_user_backfill');
    }

    /** A pre-multi-user database: rows and a token without workspace_id, the workspace not created yet. */
    private function legacyRows(): array
    {
        $this->backfill()->down();
        $captain = User::query()->create(['name' => 'Captain William Diego', 'email' => 'captain@lantis.internal', 'password' => Hash::make('x')]);
        $agentBot = User::query()->create(['name' => 'Bot', 'email' => 'bot@lantis.internal', 'password' => Hash::make('x')]);
        $captain->createToken('ceo', ['ceo']);
        $agentBot->createToken('agent-janice');
        DB::table('agents')->insert(['id' => 'agent-janice', 'name' => 'Janice', 'role_label' => 'PM']);
        DB::table('projects')->insert(['id' => 'proj-x', 'name' => 'X', 'slug' => 'x', 'owner_agent_id' => 'agent-janice']);
        DB::table('tickets')->insert(['id' => 'tk-1', 'project_id' => 'proj-x', 'key' => 'X-1', 'title' => 'T', 'status' => 'todo', 'created_by' => 'agent-janice', 'created_by_type' => 'agent']);
        DB::table('usage_days')->insert(['date' => '2026-10-30', 'generated_at' => '2026-10-31 00:00:00', 'est_tokens_total' => 1, 'payload' => '{}']);

        return [$captain, $agentBot];
    }

    #[Test]
    public function fresh_install_gets_tilens_workspace_with_an_admin_owner(): void
    {
        $ws = DB::table('workspaces')->where('slug', 'tilen')->first();
        $this->assertNotNull($ws);
        $this->assertSame('LANTIS', $ws->name);
        $this->assertSame(['polymarket' => true], json_decode($ws->features, true));
        $owner = User::query()->find($ws->owner_user_id);
        $this->assertTrue((bool) $owner->is_admin);
        $this->assertSame('owner', DB::table('workspace_user')->where(['workspace_id' => $ws->id, 'user_id' => $owner->id])->value('role'));
    }

    #[Test]
    public function backfill_assigns_every_row_and_token_to_the_token_owner_workspace(): void
    {
        [$captain, $agentBot] = $this->legacyRows();
        $tokens = DB::table('personal_access_tokens')->get(['id', 'token', 'tokenable_id', 'abilities']);

        $this->backfill()->up();
        $this->backfill()->up(); // idempotent

        $ws = DB::table('workspaces')->where('slug', 'tilen')->first();
        $this->assertSame(1, DB::table('workspaces')->count());
        $this->assertSame($captain->id, (int) $ws->owner_user_id, 'the ceo token owner becomes the owner');
        $this->assertTrue((bool) $captain->fresh()->is_admin);
        $this->assertSame('member', DB::table('workspace_user')->where(['workspace_id' => $ws->id, 'user_id' => $agentBot->id])->value('role'));
        foreach (['agents', 'projects', 'tickets', 'usage_days', 'personal_access_tokens'] as $t) {
            $this->assertSame(0, DB::table($t)->whereNull('workspace_id')->count(), $t);
            $this->assertSame(0, DB::table($t)->where('workspace_id', '!=', $ws->id)->count(), $t);
        }
        // Tokens unchanged apart from workspace_id: hash, user and abilities kept.
        $this->assertEquals($tokens, DB::table('personal_access_tokens')->get(['id', 'token', 'tokenable_id', 'abilities']));
    }

    #[Test]
    public function owner_email_from_config_wins_and_is_created_when_missing(): void
    {
        $this->legacyRows();
        config(['bridge.owner_email' => 'tilen@example.test']);

        $this->backfill()->up();

        $owner = User::query()->where('email', 'tilen@example.test')->firstOrFail();
        $this->assertTrue((bool) $owner->is_admin);
        $this->assertSame($owner->id, (int) DB::table('workspaces')->where('slug', 'tilen')->value('owner_user_id'));
        $this->assertFalse(Hash::check('', $owner->password));
    }

    #[Test]
    public function backfill_down_nulls_only_and_keeps_every_row(): void
    {
        $this->legacyRows();
        $this->backfill()->up();
        $counts = collect(['users', 'agents', 'projects', 'tickets', 'usage_days', 'personal_access_tokens'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

        $this->backfill()->down();

        $this->assertSame(0, DB::table('workspaces')->count());
        $this->assertSame(0, DB::table('workspace_user')->count());
        $this->assertSame(0, DB::table('projects')->whereNotNull('workspace_id')->count());
        foreach ($counts as $t => $n) {
            $this->assertSame($n, DB::table($t)->count(), $t);
        }
    }

    #[Test]
    public function usage_keys_are_per_workspace_and_down_refuses_to_merge_workspaces(): void
    {
        $other = DB::table('workspaces')->insertGetId(['slug' => 'other', 'name' => 'Other', 'owner_user_id' => User::factory()->create()->id]);
        $tilen = DB::table('workspaces')->where('slug', 'tilen')->value('id');
        foreach ([$tilen, $other] as $ws) {
            DB::table('usage_days')->insert(['workspace_id' => $ws, 'date' => '2026-10-30', 'generated_at' => '2026-10-31 00:00:00', 'est_tokens_total' => 1, 'payload' => '{}']);
            DB::table('labels')->insert(['workspace_id' => $ws, 'id' => "lb-$ws", 'name' => 'bug']);
        }

        $this->expectException(RuntimeException::class);
        try {
            $this->migration('2026_10_31_120000_multi_user_workspace_keys')->down();
        } finally {
            $this->assertSame(2, DB::table('usage_days')->count());
        }
    }
}
