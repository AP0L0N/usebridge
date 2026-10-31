<?php

namespace Tests\Feature\MultiUser;

use App\Models\User;
use App\Services\Ops\CopiedTokenRotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Staging ops (docs/MULTI-USER-PLAN.md §11): bridge:set-owner on the staging state (Captain's API user ended up owner +
 * admin, no Tilen user) and bridge:rotate-copied-tokens (guards, in-place rotation, 0600 file, no token in the output).
 */
class OpsCommandsTest extends TestCase
{
    use RefreshDatabase;

    private User $captain;

    /** @var array<string, string> token name => old plaintext (id|token) */
    private array $plain = [];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/bridge-ops-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    /** bridge_staging: the backfill picked Captain (only user) as owner + admin of 'tilen'; 'ceo' + an agent token. */
    private function stagingState(): void
    {
        $backfill = require database_path('migrations/2026_10_31_110000_multi_user_backfill.php');
        $backfill->down();
        DB::table('users')->delete(); // test fixture only: Captain is the only user, as on staging
        $this->captain = User::query()->create(['name' => 'Captain William Diego', 'email' => 'captain@lantis.internal', 'password' => Hash::make('x')]);
        $this->captain->forceFill(['email_verified_at' => now()])->save();
        $this->plain['ceo'] = $this->captain->createToken('ceo', ['ceo'])->plainTextToken;
        $this->plain['agent-janice'] = $this->captain->createToken('agent-janice')->plainTextToken;
        config(['bridge.owner_email' => 'captain@lantis.internal']); // what the old fallback did
        $backfill->up();
        config(['bridge.owner_email' => 'owner@example.test']);
        $this->assertTrue((bool) $this->captain->fresh()->is_admin);
    }

    private function tokens(): array
    {
        return DB::table('personal_access_tokens')->orderBy('id')
            ->get(['id', 'token', 'tokenable_type', 'tokenable_id', 'name', 'abilities', 'workspace_id', 'expires_at', 'last_used_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** users + memberships + workspaces, without timestamps */
    private function state(): array
    {
        return [
            DB::table('users')->orderBy('id')->get(['id', 'email', 'is_admin', 'deleted_at'])->map(fn ($r) => (array) $r)->all(),
            DB::table('workspace_user')->orderBy('workspace_id')->orderBy('user_id')->get(['workspace_id', 'user_id', 'role'])->map(fn ($r) => (array) $r)->all(),
            DB::table('workspaces')->orderBy('id')->get(['id', 'slug', 'owner_user_id'])->map(fn ($r) => (array) $r)->all(),
            $this->tokens(),
        ];
    }

    private function runCommand(string $command, array $options): array
    {
        $code = Artisan::call($command, $options);

        return [$code, Artisan::output()];
    }

    private function getProjects(string $token): int
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer $token")->getJson('/api/projects')->status();
    }

    // --- bridge:set-owner -----------------------------------------------------------------------------------------

    #[Test]
    public function set_owner_on_the_staging_state_creates_tilen_and_demotes_captain_keeping_his_tokens(): void
    {
        $this->stagingState();
        $tokens = $this->tokens();
        $ws = DB::table('workspaces')->where('slug', 'tilen')->first();

        [$code, $out] = $this->runCommand('bridge:set-owner', ['--email' => 'Tilen@Example.test', '--name' => 'Tilen']);

        $this->assertSame(0, $code, $out);
        $tilen = User::query()->where('email', 'tilen@example.test')->firstOrFail();
        $this->assertStringContainsString("owner user id: {$tilen->id}", $out);
        $this->assertStringContainsString('created: yes', $out);
        $this->assertStringContainsString("demoted to member (is_admin off): {$this->captain->id}", $out);
        $this->assertStringNotContainsString('http', $out);
        $this->assertSame('Tilen', $tilen->name);
        $this->assertTrue($tilen->is_admin);
        $this->assertTrue($tilen->hasVerifiedEmail());
        $this->assertFalse(Hash::check('', $tilen->password));
        $this->assertSame($tilen->id, (int) DB::table('workspaces')->where('id', $ws->id)->value('owner_user_id'));
        $this->assertSame('owner', DB::table('workspace_user')->where(['workspace_id' => $ws->id, 'user_id' => $tilen->id])->value('role'));
        $this->assertSame('member', DB::table('workspace_user')->where(['workspace_id' => $ws->id, 'user_id' => $this->captain->id])->value('role'));
        $this->assertFalse($this->captain->fresh()->is_admin);
        $this->assertSame($tokens, $this->tokens(), 'token ids, hashes, abilities and workspace untouched');
        foreach ($this->plain as $plain) {
            $this->assertSame(200, $this->getProjects($plain));
        }

        $state = $this->state();
        [$code, $out] = $this->runCommand('bridge:set-owner', ['--email' => 'tilen@example.test']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('created: no', $out);
        $this->assertStringContainsString('(is_admin off): none', $out);
        $this->assertSame($state, $this->state(), 'second run: identical state, no duplicates');
        $this->assertSame(1, User::query()->where('email', 'tilen@example.test')->count());
    }

    #[Test]
    public function set_owner_promotes_an_existing_user_without_creating_one(): void
    {
        $this->stagingState();
        $marta = $this->workspaceUser(['email' => 'marta@example.test']);
        $users = User::withTrashed()->count();

        [$code, $out] = $this->runCommand('bridge:set-owner', ['--email' => 'marta@example.test']);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('created: no', $out);
        $this->assertSame($users, User::withTrashed()->count());
        $this->assertTrue($marta->fresh()->is_admin);
        $this->assertSame('owner', DB::table('workspace_user')->where('user_id', $marta->id)->value('role'));
        $this->assertSame($marta->id, (int) DB::table('workspaces')->where('slug', 'tilen')->value('owner_user_id'));
        $this->assertFalse($this->captain->fresh()->is_admin);
    }

    #[Test]
    public function set_owner_dry_run_changes_nothing(): void
    {
        $this->stagingState();
        $state = $this->state();

        [$code, $out] = $this->runCommand('bridge:set-owner', ['--email' => 'tilen@example.test', '--dry-run' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('owner user id: new', $out);
        $this->assertStringContainsString("(is_admin off): {$this->captain->id}", $out);
        $this->assertStringContainsString('nothing changed', $out);
        $this->assertSame($state, $this->state());
    }

    #[Test]
    public function set_owner_refuses_an_unknown_workspace_an_invalid_email_and_a_soft_deleted_or_disabled_user(): void
    {
        $this->stagingState();
        $gone = $this->workspaceUser(['email' => 'gone@example.test']);
        $gone->delete();
        $off = $this->workspaceUser(['email' => 'off@example.test']);
        $off->forceFill(['disabled_at' => now()])->save();
        $state = $this->state();

        foreach ([
            ['--email' => 'tilen@example.test', '--workspace' => 'nope'],
            ['--email' => 'not-an-email'],
            ['--email' => 'gone@example.test'],
            ['--email' => 'off@example.test'],
        ] as $options) {
            [$code, $out] = $this->runCommand('bridge:set-owner', $options);
            $this->assertSame(1, $code, $out);
            $this->assertSame($state, $this->state(), $out);
        }
        $this->assertStringContainsString('soft-deleted', $this->runCommand('bridge:set-owner', ['--email' => 'gone@example.test'])[1]);
    }

    // --- bridge:rotate-copied-tokens --------------------------------------------------------------------------------

    #[Test]
    public function rotation_guards_refuse_production_local_without_force_the_live_host_and_an_existing_file(): void
    {
        $this->stagingState();
        $tokens = $this->tokens();
        $out = $this->dir.'/tokens.env';
        $refuse = function (array $options, string $message) use ($tokens) {
            [$code, $output] = $this->runCommand('bridge:rotate-copied-tokens', $options);
            $this->assertSame(1, $code, $output);
            $this->assertStringContainsString($message, $output);
            $this->assertSame($tokens, $this->tokens());
        };

        $this->app['env'] = 'production';
        $refuse(['--out' => $out, '--force-env' => 'staging'], 'production');
        $this->app['env'] = 'local';
        $refuse(['--out' => $out], 'not staging');
        $refuse(['--out' => $out, '--force-env' => 'local'], 'only accepts');
        $this->app['env'] = 'staging';
        foreach (['https://bridge.tilenpoje.eu', 'https://WWW.Bridge.TilenPoje.eu/', 'http://bridge.tilenpoje.eu.:8080/x'] as $url) {
            config(['app.url' => $url]);
            $refuse(['--out' => $out], 'live Bridge');
        }
        config(['app.url' => 'https://bridge-staging.tilenpoje.eu']);
        $refuse([], '--out');
        $refuse(['--out' => $this->dir.'/missing/tokens.env'], 'not a writable directory');
        file_put_contents($out, 'keep');
        $refuse(['--out' => $out], 'already exists');
        $this->assertSame('keep', file_get_contents($out));
        $this->assertFalse(is_file($this->dir.'/missing/tokens.env'));
    }

    #[Test]
    public function rotation_replaces_every_plaintext_in_place_and_writes_them_only_to_a_0600_file(): void
    {
        $this->stagingState();
        $bot = $this->workspaceUser(['email' => 'bot@example.test']);
        $this->plain['agent-janice-2'] = $bot->createToken('agent-janice')->plainTextToken; // repeated name
        $expired = $bot->createToken('old one', ['boards:read'], now()->subDay());
        $before = $this->tokens();
        $out = $this->dir.'/tokens.env';
        config(['app.url' => 'http://localhost']);

        [$code, $output] = $this->runCommand('bridge:rotate-copied-tokens', ['--out' => $out, '--force-env' => 'staging', '--dry-run' => true]);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('would rotate 4 token(s)', $output);
        $this->assertFalse(file_exists($out));
        $this->assertSame($before, $this->tokens());

        [$code, $output] = $this->runCommand('bridge:rotate-copied-tokens', ['--out' => $out, '--force-env' => 'staging']);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString("Rotated 4 token(s); new values written to $out", $output);
        $this->assertSame(0600, fileperms($out) & 0777);
        $lines = file($out, FILE_IGNORE_NEW_LINES);
        $this->assertStringStartsWith('# bridge:rotate-copied-tokens ', $lines[0]);
        $this->assertStringEndsWith(', 4 tokens', $lines[0]);
        $values = [];
        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = explode('=', $line, 2);
            $values[$name] = $value;
            $this->assertStringNotContainsString(explode('|', $value)[1], $output, 'no new token in the output');
        }
        $ids = array_column($before, 'id', 'name');
        $janice = array_values(array_filter($before, fn ($t) => $t['name'] === 'agent-janice'));
        $this->assertSame(['BRIDGE_API_TOKEN', 'AGENT_JANICE_'.$janice[0]['id'], 'AGENT_JANICE_'.$janice[1]['id'], 'OLD_ONE'], array_keys($values));
        $this->assertStringStartsWith($ids['ceo'].'|'.config('sanctum.token_prefix'), $values['BRIDGE_API_TOKEN']);

        $after = $this->tokens();
        $this->assertCount(count($before), $after);
        foreach ($before as $i => $row) {
            $this->assertNotSame($row['token'], $after[$i]['token']);
            unset($row['token'], $after[$i]['token']);
            $this->assertSame($row, $after[$i], 'id, owner, name, abilities, workspace, expiry kept');
        }
        $this->assertSame(substr($values['OLD_ONE'], -4), DB::table('personal_access_tokens')->where('id', $expired->accessToken->id)->value('token_suffix'));

        foreach ($this->plain as $plain) {
            $this->assertSame(401, $this->getProjects($plain), 'old plaintext no longer works');
        }
        foreach (['BRIDGE_API_TOKEN', 'AGENT_JANICE_'.$janice[0]['id'], 'AGENT_JANICE_'.$janice[1]['id']] as $name) {
            $this->assertSame(200, $this->getProjects($values[$name]), $name);
        }
        $this->assertSame(401, $this->getProjects($values['OLD_ONE']), 'expired stays expired');
    }

    #[Test]
    public function a_failed_file_write_rolls_the_rotation_back(): void
    {
        $this->stagingState();
        $before = $this->tokens();

        try {
            app(CopiedTokenRotation::class)->rotate($this->dir.'/missing/tokens.env', false);
            $this->fail('expected the write to fail');
        } catch (RuntimeException) {
        }

        $this->assertSame($before, $this->tokens());
        foreach ($this->plain as $plain) {
            $this->assertSame(200, $this->getProjects($plain));
        }
    }

    #[Test]
    public function env_names_are_upper_case_unique_and_ceo_is_bridge_api_token(): void
    {
        $this->assertSame(
            [1 => 'BRIDGE_API_TOKEN', 2 => 'AGENT_JANICE_2', 3 => 'AGENT_JANICE_3', 4 => 'TOKEN_9LIVES', 5 => 'TOKEN', 6 => 'MY_LAPTOP'],
            CopiedTokenRotation::envNames([1 => 'ceo', 2 => 'agent-janice', 3 => 'Agent Janice', 4 => '9lives', 5 => '--', 6 => 'my laptop!']),
        );
    }
}
