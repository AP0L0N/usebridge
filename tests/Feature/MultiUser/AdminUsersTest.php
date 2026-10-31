<?php

namespace Tests\Feature\MultiUser;

use App\Models\User;
use App\Services\Auth\AccountService;
use App\Services\Auth\AdminUsers;
use App\Services\Auth\ApiTokens;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Owner admin = user list + disable/enable only (relayed decision 2); disabled = login refused, sessions gone, tokens 403. */
class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    private User $tilen;

    private User $marta;

    private string $martaToken;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        Notification::fake();
        $this->tilen = app(CurrentWorkspace::class)->default()->owner; // backfilled owner, is_admin
        $this->marta = app(AccountService::class)->register('Marta', 'marta@example.test', self::PASSWORD);
        $this->marta->markEmailAsVerified();
        $this->martaToken = app(ApiTokens::class)->create($this->marta, $this->marta->ownedWorkspaces()->first(), 'grok', 'read', null)['plain_text'];
    }

    private function bearer(string $token, string $uri): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson($uri, ['Authorization' => "Bearer $token"]);
    }

    #[Test]
    public function only_a_logged_in_admin_reaches_admin_everyone_else_gets_404(): void
    {
        $id = $this->marta->id;
        foreach (['GET /admin/users', "POST /admin/users/$id/disable", "POST /admin/users/{$this->tilen->id}/enable"] as $route) {
            [$method, $uri] = explode(' ', $route);
            $this->json($method, $uri)->assertNotFound();
            $this->withSession(['bridge_gate_unlocked' => true])->json($method, $uri)->assertNotFound(); // a leftover gate flag is nothing
            $this->actingAs($this->marta)->json($method, $uri)->assertNotFound();
            $this->app['auth']->forgetGuards();
        }
        $this->assertNull($this->marta->fresh()->disabled_at);
        $this->assertTrue($this->tilen->is_admin);
    }

    #[Test]
    public function the_list_has_exactly_the_allowed_keys_and_statuses(): void
    {
        $pending = User::factory()->create(['delete_requested_at' => Carbon::now()]);
        $disabled = User::factory()->create(['disabled_at' => Carbon::now()]);
        $this->tilen->forceFill(['last_login_at' => Carbon::now()])->save();

        $data = $this->actingAs($this->tilen)->getJson('/admin/users')->assertOk()->json('data');

        foreach ($data as $row) {
            $this->assertSame(AdminUsers::KEYS, array_keys($row));
            $this->assertSame(['id', 'name', 'email', 'created_at', 'last_login_at', 'status'], array_keys($row));
        }
        $status = array_column($data, 'status', 'id');
        $this->assertSame('active', $status[$this->marta->id]);
        $this->assertSame('deletion_pending', $status[$pending->id]);
        $this->assertSame('disabled', $status[$disabled->id]);
        $this->assertSame(Carbon::now()->toIso8601String(), array_column($data, 'last_login_at', 'id')[$this->tilen->id]);
    }

    #[Test]
    public function an_admin_cannot_disable_or_enable_themselves(): void
    {
        $this->actingAs($this->tilen)->postJson("/admin/users/{$this->tilen->id}/disable")->assertStatus(422);
        $this->actingAs($this->tilen)->postJson("/admin/users/{$this->tilen->id}/enable")->assertStatus(422);
        $this->actingAs($this->tilen)->postJson('/admin/users/999999/disable')->assertNotFound();
        $this->assertNull($this->tilen->fresh()->disabled_at);
    }

    #[Test]
    public function disable_refuses_login_ends_sessions_and_blocks_tokens_and_enable_restores_them(): void
    {
        DB::table('sessions')->insert(['id' => 'marta-phone', 'user_id' => $this->marta->id, 'payload' => '', 'last_activity' => time()]);
        $this->bearer($this->martaToken, '/api/projects')->assertOk();

        $this->actingAs($this->tilen, 'web')->postJson("/admin/users/{$this->marta->id}/disable")->assertOk()
            ->assertJsonPath('data.status', 'disabled');
        $this->app['auth']->forgetGuards();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->marta->id)->count());
        $this->bearer($this->martaToken, '/api/projects')->assertForbidden()->assertJsonPath('message', 'This account is disabled.');
        $this->assertSame(1, $this->marta->tokens()->count(), 'blocked, not revoked');
        $this->app['auth']->forgetGuards();
        $this->postJson('/login', ['email' => 'marta@example.test', 'password' => self::PASSWORD])->assertForbidden();
        $this->assertGuest('web');

        $this->actingAs($this->tilen, 'web')->postJson("/admin/users/{$this->marta->id}/enable")->assertOk()->assertJsonPath('data.status', 'active');
        $this->app['auth']->forgetGuards();
        $this->bearer($this->martaToken, '/api/projects')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/login', ['email' => 'marta@example.test', 'password' => self::PASSWORD])->assertOk();
    }
}
