<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Services\Auth\AccountDeletion;
use App\Services\Auth\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\McpHelpers;
use Tests\TestCase;

/** Account screen backend: PATCH /account profile, PUT /account/password, delete request + export, tokens over HTTP. */
class AccountSettingsTest extends TestCase
{
    use McpHelpers;
    use RefreshDatabase;

    private const PASSWORD = 'garden-planner-27';

    private User $sam;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        config(['app.url' => 'https://bridge.example.test']);
        Notification::fake();
        $this->sam = app(AccountService::class)->register('Sam Rivera', 'sam@example.com', self::PASSWORD, 'Acme Studio');
        $this->sam->markEmailAsVerified();
        $this->sam = $this->sam->fresh();
    }

    private function sessions(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('sessions')->insert(['id' => "dev-$i", 'user_id' => $this->sam->id, 'ip_address' => '10.0.0.'.$i,
                'user_agent' => 'Test', 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        }
    }

    #[Test]
    public function profile_saves_name_and_timezone_at_once(): void
    {
        Notification::fake();
        $this->actingAs($this->sam)->patchJson('/account', ['name' => '  Sam R. ', 'timezone' => 'America/New_York', 'email' => 'sam@example.com'])
            ->assertOk()->assertJsonPath('user.name', 'Sam R.')->assertJsonPath('user.timezone', 'America/New_York')
            ->assertJsonPath('user.pending_email', null)->assertJsonPath('email_change_started', false);

        $fresh = $this->sam->fresh();
        $this->assertSame(['Sam R.', 'America/New_York', 'sam@example.com'], [$fresh->name, $fresh->timezone, $fresh->email]);
        Notification::assertNothingSent();

        // email may be left out (name / timezone only)
        $this->actingAs($this->sam)->patchJson('/account', ['name' => 'Sam Rivera', 'timezone' => 'UTC'])->assertOk();
        $this->assertSame('UTC', $this->sam->fresh()->timezone);
    }

    #[Test]
    public function profile_errors_come_per_field(): void
    {
        $this->actingAs($this->sam)->patchJson('/account', ['name' => '', 'timezone' => 'Mars/Olympus', 'email' => 'sam.rivera@'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', __('account.profile.name_required'))
            ->assertJsonPath('errors.timezone.0', __('account.profile.timezone_invalid'))
            ->assertJsonPath('errors.email.0', __('account.profile.email_invalid'));
        $this->actingAs($this->sam)->patchJson('/account', ['name' => str_repeat('a', 121), 'timezone' => 'UTC'])
            ->assertStatus(422)->assertJsonValidationErrors(['name'])->assertJsonMissingValidationErrors(['timezone']);
        $this->assertSame(['Sam Rivera', 'Europe/Ljubljana'], [$this->sam->fresh()->name, $this->sam->fresh()->timezone]);

        // a refused email change does not half-save the name
        $this->actingAs($this->sam)->patchJson('/account', ['name' => 'Sam New', 'timezone' => 'UTC', 'email' => 'new@example.com', 'current_password' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->assertSame('Sam Rivera', $this->sam->fresh()->name);
    }

    #[Test]
    public function guests_cannot_patch_the_profile(): void
    {
        $this->patchJson('/account', ['name' => 'X', 'timezone' => 'UTC'])->assertUnauthorized();
    }

    #[Test]
    public function password_change_validates_and_ends_other_sessions(): void
    {
        $this->sessions(2);
        $put = fn (array $d) => $this->actingAs($this->sam)->putJson('/account/password', $d);

        $put(['current_password' => 'wrong one', 'password' => 'new long password', 'password_confirmation' => 'new long password'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $put(['current_password' => self::PASSWORD, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $put(['current_password' => self::PASSWORD, 'password' => 'new long password', 'password_confirmation' => 'other long password'])
            ->assertStatus(422)->assertJsonPath('errors.password_confirmation.0', __('account.password.mismatch'));
        $this->assertTrue(Hash::check(self::PASSWORD, $this->sam->fresh()->password));
        $this->assertSame(2, DB::table('sessions')->where('user_id', $this->sam->id)->count());

        $put(['current_password' => self::PASSWORD, 'password' => 'new long password', 'password_confirmation' => 'new long password'])->assertOk();
        $this->assertTrue(Hash::check('new long password', $this->sam->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->sam->id)->count());
        $this->assertAuthenticatedAs($this->sam);
    }

    #[Test]
    public function delete_request_starts_the_grace_and_export_still_works(): void
    {
        $this->actingAs($this->sam)->getJson('/account/export')->assertOk()->assertJsonPath('workspace.slug', 'acme-studio');

        $this->actingAs($this->sam)->postJson('/account/delete', ['email' => 'other@example.com'])->assertStatus(422);
        $this->actingAs($this->sam)->postJson('/account/delete', ['email' => 'SAM@example.com'])->assertOk()
            ->assertJsonPath('purge_at', now()->addDays(AccountDeletion::GRACE_DAYS)->toIso8601String());

        $fresh = $this->sam->fresh();
        $this->assertNotNull($fresh->delete_requested_at);
        $this->assertNull($fresh->deleted_at);
        $this->assertGuest();

        app(AccountDeletion::class)->deleteExpired();
        $this->assertFalse($this->sam->fresh()->trashed());
        Carbon::setTestNow(now()->addDays(AccountDeletion::GRACE_DAYS)->addMinute());
        app(AccountDeletion::class)->deleteExpired();
        $this->assertTrue(User::withTrashed()->find($this->sam->id)->trashed());
    }

    #[Test]
    public function tokens_created_over_http_show_the_plaintext_once_and_stop_working_when_revoked(): void
    {
        $res = $this->actingAs($this->sam)->postJson('/account/tokens', ['name' => 'Ava (bot)', 'scope' => 'write', 'expires' => null])
            ->assertCreated()->assertJsonPath('data.scope', 'write')->assertJsonPath('data.expires_at', null);
        $plain = $res->json('plain_text_token');
        $this->assertStringStartsWith('brg_live_', $plain);
        $id = $res->json('data.id');

        foreach ([30, 90, 365] as $days) {
            $this->actingAs($this->sam)->postJson('/account/tokens', ['name' => "Laptop test $days", 'scope' => 'read', 'expires' => $days])
                ->assertCreated()->assertJsonPath('data.scope', 'read')
                ->assertJsonPath('data.expires_at', now()->addDays($days)->toIso8601String());
        }
        $this->actingAs($this->sam)->postJson('/account/tokens', ['name' => 'x', 'scope' => 'read', 'expires' => 7])->assertStatus(422);
        $this->actingAs($this->sam)->postJson('/account/tokens', ['name' => 'x', 'scope' => 'admin'])->assertStatus(422);

        $page = $this->actingAs($this->sam)->get('/account/tokens')->assertInertia(fn (Assert $p) => $p
            ->component('Account/Tokens')->has('tokens', 4)->where('mcpUrl', 'https://bridge.example.test/mcp'));
        $this->assertStringNotContainsString($plain, $page->getContent());
        $this->assertStringNotContainsString($plain, $this->actingAs($this->sam)->getJson('/account/tokens')->getContent());

        $this->mcp($plain, 'ping')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/projects', ['Authorization' => "Bearer $plain"])->assertOk();

        $this->app['auth']->forgetGuards();
        // auth:sanctum switched the default guard: act on the session guard again.
        $this->actingAs($this->sam, 'web')->deleteJson("/account/tokens/$id")->assertOk();
        $this->mcp($plain, 'ping')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/projects', ['Authorization' => "Bearer $plain"])->assertUnauthorized();
    }

    #[Test]
    public function unverified_users_cannot_create_tokens(): void
    {
        $ava = app(AccountService::class)->register('Ava', 'ava@example.com', self::PASSWORD);
        $this->actingAs($ava)->postJson('/account/tokens', ['name' => 'bot', 'scope' => 'read'])->assertForbidden();
        $this->assertSame(0, $ava->tokens()->count());
    }
}
