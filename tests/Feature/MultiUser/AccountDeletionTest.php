<?php

namespace Tests\Feature\MultiUser;

use App\Models\Agent;
use App\Models\Project;
use App\Models\User;
use App\Services\Auth\AccountService;
use App\Services\Auth\ApiTokens;
use App\Support\Workspaces\CurrentWorkspace;
use Database\Seeders\BridgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Delete request (7-day grace, logged out everywhere, tokens blocked), cancel, soft delete after the grace, export. */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    private User $marta;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        Notification::fake();
        $this->seed(BridgeSeeder::class); // Tilen's projects and agents
        $this->marta = app(AccountService::class)->register('Marta', 'marta@example.test', self::PASSWORD);
        $this->marta->markEmailAsVerified();
        $workspace = $this->marta->ownedWorkspaces()->first();
        $this->token = app(ApiTokens::class)->create($this->marta, $workspace, 'grok', 'read', null)['plain_text'];
        app(CurrentWorkspace::class)->runAs($workspace, function () {
            Agent::query()->create(['id' => 'agent-marta', 'name' => 'Marta Bot', 'role_label' => 'PM']);
            Project::query()->create(['id' => 'proj-marta', 'name' => 'Marta Lab', 'slug' => 'marta-lab', 'owner_agent_id' => 'agent-marta']);
        });
    }

    private function api(): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/projects', ['Authorization' => "Bearer {$this->token}"]);
    }

    #[Test]
    public function a_deletion_request_needs_the_typed_email_and_logs_out_everywhere(): void
    {
        DB::table('sessions')->insert(['id' => 'marta-phone', 'user_id' => $this->marta->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->marta, 'web')->postJson('/account/delete', ['email' => 'someone@example.test', 'current_password' => self::PASSWORD])->assertStatus(422);
        $this->assertNull($this->marta->fresh()->delete_requested_at);

        // Taz pre-prod pass #4: the right email alone (no / wrong current password) deletes nothing.
        $this->actingAs($this->marta, 'web')->postJson('/account/delete', ['email' => 'marta@example.test'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->actingAs($this->marta, 'web')->postJson('/account/delete', ['email' => 'marta@example.test', 'current_password' => 'not-my-password-1'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->assertNull($this->marta->fresh()->delete_requested_at);
        $this->assertAuthenticatedAs($this->marta, 'web');

        $this->actingAs($this->marta, 'web')->postJson('/account/delete', ['email' => ' MARTA@example.test ', 'current_password' => self::PASSWORD])->assertOk()
            ->assertJsonPath('purge_at', Carbon::now()->addDays(7)->toIso8601String());

        $this->assertTrue($this->marta->fresh()->delete_requested_at->equalTo(Carbon::now()));
        $this->assertGuest('web');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->marta->id)->count());
        $this->api()->assertForbidden()->assertJsonPath('message', 'This account is scheduled for deletion.');
        $this->assertSame(1, $this->marta->tokens()->count(), 'blocked, not revoked');
    }

    #[Test]
    public function cancelling_within_the_grace_restores_the_account_but_not_after_it(): void
    {
        $this->marta->forceFill(['delete_requested_at' => Carbon::now()])->save();
        $this->travel(6)->days();
        $this->postJson('/login/cancel-deletion', ['email' => 'marta@example.test', 'password' => self::PASSWORD])->assertOk();
        $this->api()->assertOk();

        $this->marta->forceFill(['delete_requested_at' => Carbon::now()])->save();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->travel(8)->days();
        $this->postJson('/login/cancel-deletion', ['email' => 'marta@example.test', 'password' => self::PASSWORD])->assertStatus(422);
        $this->assertNotNull($this->marta->fresh()->delete_requested_at);
    }

    #[Test]
    public function the_command_is_a_no_op_while_the_purge_flag_is_off(): void
    {
        config(['bridge.account_purge_enabled' => false]);
        $this->marta->forceFill(['delete_requested_at' => Carbon::now()])->save();
        $this->travelTo(Carbon::now()->addDays(30));
        $before = User::withTrashed()->whereNotNull('deleted_at')->count();

        $this->artisan('bridge:accounts-delete-expired')->expectsOutputToContain('disabled')->assertSuccessful();

        $this->assertFalse($this->marta->fresh()->trashed());
        $this->assertSame($before, User::withTrashed()->whereNotNull('deleted_at')->count());
    }

    #[Test]
    public function the_command_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('bridge:accounts-delete-expired')->assertSuccessful();
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'bridge:accounts-delete-expired'));
        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
        config(['bridge.account_purge_enabled' => false]);
        $this->assertFalse($events->first()->filtersPass($this->app), 'skipped while the flag is off');
    }

    #[Test]
    public function the_command_soft_deletes_after_seven_days_and_keeps_every_row(): void
    {
        config(['bridge.account_purge_enabled' => true]);
        $this->marta->forceFill(['delete_requested_at' => Carbon::now()])->save();
        $early = User::factory()->create(['delete_requested_at' => Carbon::now()->addDays(3)]);
        $this->travelTo(Carbon::now()->addDays(7)->addMinute());

        $this->artisan('bridge:accounts-delete-expired --dry-run')->assertSuccessful();
        $this->assertFalse($this->marta->fresh()->trashed());

        $this->artisan('bridge:accounts-delete-expired')->expectsOutputToContain('Soft-deleted 1 user(s)')->assertSuccessful();

        $this->assertNull(User::query()->find($this->marta->id));
        $this->assertTrue(User::withTrashed()->find($this->marta->id)->trashed());
        $this->assertNotNull(User::query()->find($early->id));
        $ws = DB::table('workspaces')->where('owner_user_id', $this->marta->id)->first();
        $this->assertNotNull($ws, 'workspace kept');
        $this->assertSame(1, DB::table('projects')->where('workspace_id', $ws->id)->count(), 'no purge');
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $this->marta->id)->count());
        $this->api()->assertUnauthorized(); // the token's user is gone
        $this->postJson('/login', ['email' => 'marta@example.test', 'password' => self::PASSWORD])->assertStatus(422);
    }

    #[Test]
    public function export_contains_only_the_users_own_workspace(): void
    {
        $res = $this->actingAs($this->marta, 'web')->getJson('/account/export')->assertOk();

        $this->assertStringContainsString('attachment; filename="bridge-marta-', $res->headers->get('Content-Disposition'));
        $res->assertJsonPath('workspace.slug', 'marta')
            ->assertJsonCount(1, 'projects')->assertJsonPath('projects.0.id', 'proj-marta')
            ->assertJsonCount(1, 'agents')->assertJsonPath('agents.0.id', 'agent-marta')
            ->assertJsonCount(0, 'tickets')->assertJsonCount(0, 'usage_days');
        $this->assertStringNotContainsString(BridgeSeeder::PROJ_BLUE_HARBOR, $res->getContent());

        // Tilen exports his own (seeded) workspace and never Marta's.
        $tilen = app(CurrentWorkspace::class)->default()->owner;
        $this->app['auth']->forgetGuards();
        $mine = $this->actingAs($tilen, 'web')->getJson('/account/export')->assertOk();
        $mine->assertJsonPath('workspace.slug', config('bridge.default_workspace'))->assertJsonCount(2, 'projects');
        $this->assertStringNotContainsString('proj-marta', $mine->getContent());
    }
}
