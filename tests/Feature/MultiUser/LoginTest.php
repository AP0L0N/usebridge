<?php

namespace Tests\Feature\MultiUser;

use App\Models\User;
use App\Services\Auth\AccountService;
use App\Services\Auth\DeviceSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** POST /login: lockout (per account, neutral for unknown emails, per IP), refusals, sessions, password changes. */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        $this->user = $this->workspaceUser(['email' => 'marta@example.test', 'password' => Hash::make(self::PASSWORD)]);
    }

    private function attempt(string $password, string $email = 'marta@example.test', string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/login', ['email' => $email, 'password' => $password]);
    }

    private function sessionRows(User $user, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('sessions')->insert([
                'id' => "sess-{$user->id}-$i", 'user_id' => $user->id, 'ip_address' => '10.0.0.'.$i,
                'user_agent' => 'Test', 'payload' => '', 'last_activity' => Carbon::now()->getTimestamp() - $i,
            ]);
        }
    }

    #[Test]
    public function the_right_password_logs_in_and_records_last_login(): void
    {
        $this->user->forceFill(['failed_logins' => 3])->save();

        $this->attempt(self::PASSWORD)->assertOk()->assertJsonPath('user.email', 'marta@example.test');

        $this->assertAuthenticatedAs($this->user);
        $fresh = $this->user->fresh();
        $this->assertSame(0, $fresh->failed_logins);
        $this->assertTrue($fresh->last_login_at->equalTo(Carbon::now()));
    }

    #[Test]
    public function five_wrong_passwords_lock_the_account_for_fifteen_minutes(): void
    {
        foreach ([4, 3, 2, 1] as $left) {
            $this->attempt('wrong password')->assertStatus(422)->assertJsonPath('tries_left', $left)->assertJsonValidationErrors('email');
        }
        $this->attempt('wrong password')->assertStatus(429)->assertJsonPath('locked_until', Carbon::now()->addMinutes(15)->toIso8601String());
        $this->assertSame(0, $this->user->fresh()->failed_logins);

        // Even the right password is refused while locked.
        $this->attempt(self::PASSWORD)->assertStatus(429);
        $this->assertGuest();

        $this->travel(16)->minutes();
        $this->attempt(self::PASSWORD)->assertOk();
        $this->assertNull($this->user->fresh()->locked_until);
    }

    #[Test]
    public function unknown_emails_get_the_same_answers_as_known_ones(): void
    {
        $known = [];
        $unknown = [];
        for ($i = 0; $i < 6; $i++) {
            $k = $this->attempt('wrong password', 'marta@example.test', '10.0.0.1');
            $u = $this->attempt('wrong password', 'nobody@example.test', '10.0.0.2');
            $known[] = [$k->status(), array_keys($k->json()), $k->json('tries_left'), $k->json('message')];
            $unknown[] = [$u->status(), array_keys($u->json()), $u->json('tries_left'), $u->json('message')];
        }

        $this->assertSame($known, $unknown);
        $this->assertSame([422, 422, 422, 422, 429, 429], array_column($unknown, 0));
    }

    #[Test]
    public function ten_failures_per_minute_from_one_ip_are_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->attempt('wrong', "nobody$i@example.test")->assertStatus(422);
        }
        $this->attempt(self::PASSWORD)->assertStatus(429)->assertJsonStructure(['message', 'retry_after']);
        $this->attempt(self::PASSWORD, ip: '10.0.0.9')->assertOk();
    }

    #[Test]
    public function a_password_reset_clears_the_lock_and_ends_every_session(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('wrong password');
        }
        $this->assertNotNull($this->user->fresh()->locked_until);
        $this->sessionRows($this->user, 2);
        $remember = $this->user->fresh()->remember_token;

        $token = Password::broker()->createToken($this->user);
        $this->postJson('/reset-password', ['email' => 'marta@example.test', 'token' => 'nope', 'password' => 'a new passphrase'])
            ->assertStatus(422)->assertJsonPath('expired', true);
        $this->postJson('/reset-password', ['email' => 'marta@example.test', 'token' => $token, 'password' => 'a new passphrase'])->assertOk();

        $fresh = $this->user->fresh();
        $this->assertNull($fresh->locked_until);
        $this->assertNotSame($remember, $fresh->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->user->id)->count());
        $this->attempt('a new passphrase')->assertOk();
    }

    #[Test]
    public function forgot_password_answers_the_same_for_unknown_emails(): void
    {
        $a = $this->postJson('/forgot-password', ['email' => 'marta@example.test'])->assertOk()->json();
        $b = $this->postJson('/forgot-password', ['email' => 'nobody@example.test'])->assertOk()->json();
        $this->assertSame($a, $b);
    }

    #[Test]
    public function disabled_accounts_are_refused_only_with_the_right_password(): void
    {
        $this->user->forceFill(['disabled_at' => Carbon::now()])->save();

        $this->attempt('wrong password')->assertStatus(422);
        $this->attempt(self::PASSWORD)->assertForbidden()->assertJsonPath('message', 'This account is disabled.');
        $this->assertGuest();
    }

    #[Test]
    public function a_deletion_pending_account_is_refused_but_can_cancel_within_the_grace(): void
    {
        $this->user->forceFill(['delete_requested_at' => Carbon::now()->subDays(2)])->save();

        $this->attempt(self::PASSWORD)->assertForbidden()->assertJsonPath('deletion_pending', true)
            ->assertJsonPath('purge_at', Carbon::now()->addDays(5)->toIso8601String());
        $this->assertGuest();

        $this->postJson('/login/cancel-deletion', ['email' => 'marta@example.test', 'password' => 'wrong password'])->assertStatus(422);
        $this->postJson('/login/cancel-deletion', ['email' => 'marta@example.test', 'password' => self::PASSWORD])->assertOk();
        $this->assertAuthenticatedAs($this->user);
        $this->assertNull($this->user->fresh()->delete_requested_at);
    }

    #[Test]
    public function logout_ends_the_session(): void
    {
        $this->attempt(self::PASSWORD)->assertOk();
        $this->postJson('/logout')->assertOk();
        $this->assertGuest();
        $this->getJson('/account')->assertUnauthorized();
    }

    #[Test]
    public function sessions_list_shows_only_own_devices_and_never_raw_ids(): void
    {
        $other = $this->workspaceUser();
        $this->sessionRows($this->user, 2);
        $this->sessionRows($other, 1);

        $data = $this->actingAs($this->user)->getJson('/account/sessions')->assertOk()->json('data');

        $this->assertCount(2, $data);
        $this->assertSame(['id', 'ip', 'user_agent', 'last_active', 'current'], array_keys($data[0]));
        $this->assertSame(hash('sha256', "sess-{$this->user->id}-0"), $data[0]['id']);
    }

    #[Test]
    public function log_out_other_devices_needs_the_password_and_keeps_the_current_one(): void
    {
        $other = $this->workspaceUser();
        $this->sessionRows($this->user, 3);
        $this->sessionRows($other, 1);

        $this->actingAs($this->user)->deleteJson('/account/sessions/others', ['password' => 'wrong'])->assertStatus(422);
        $this->assertSame(3, DB::table('sessions')->where('user_id', $this->user->id)->count());
        $this->actingAs($this->user)->deleteJson('/account/sessions/others', ['password' => self::PASSWORD])->assertOk()->assertJsonPath('ended', 3);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
        $this->assertAuthenticatedAs($this->user);

        // The current device's row is kept.
        $this->sessionRows($this->user, 3);
        app(DeviceSessions::class)->logoutOthers($this->user, "sess-{$this->user->id}-1");
        $this->assertSame(["sess-{$this->user->id}-1"], DB::table('sessions')->where('user_id', $this->user->id)->pluck('id')->all());
    }

    #[Test]
    public function log_out_all_devices_deletes_every_session_and_rotates_the_remember_token(): void
    {
        $this->sessionRows($this->user, 2);
        $remember = $this->user->remember_token;

        $this->actingAs($this->user)->deleteJson('/account/sessions')->assertOk()->assertJsonPath('ended', 2);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->user->id)->count());
        $this->assertNotSame($remember, $this->user->fresh()->remember_token);
        $this->assertGuest();
    }

    #[Test]
    public function changing_the_password_logs_out_the_other_devices(): void
    {
        $this->sessionRows($this->user, 2);

        $this->actingAs($this->user)->putJson('/account/password', ['current_password' => self::PASSWORD, 'password' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->actingAs($this->user)->putJson('/account/password', ['current_password' => self::PASSWORD, 'password' => 'ten chars!'])->assertOk();

        $this->assertTrue(Hash::check('ten chars!', $this->user->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->user->id)->count());
    }

    #[Test]
    public function passwords_need_ten_characters(): void
    {
        $this->assertSame(10, AccountService::MIN_PASSWORD);
        try {
            app(AccountService::class)->register('X', 'x@example.test', 'nine char');
            $this->fail('9 characters accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }
        $this->assertSame('x@example.test', app(AccountService::class)->register('X', 'x@example.test', 'ten chars!')->email);
    }

    #[Test]
    public function a_user_disabled_mid_session_is_logged_out_on_the_next_request(): void
    {
        $this->actingAs($this->user)->getJson('/account')->assertOk();
        $this->user->forceFill(['disabled_at' => Carbon::now()])->save();

        $this->getJson('/account')->assertUnauthorized()->assertJsonPath('message', 'This account is disabled.');
        $this->assertGuest();
    }
}
