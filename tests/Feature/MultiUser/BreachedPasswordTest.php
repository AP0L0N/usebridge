<?php

namespace Tests\Feature\MultiUser;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** HIBP range check on sign-up, reset and change: breached → 422, clean → ok, HIBP down/slow → allowed (fail-open). */
class BreachedPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const BREACHED = 'password1234';

    private const CLEAN = 'correct horse battery';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        Notification::fake();
        Http::preventStrayRequests();
        config(['bridge.hibp_enabled' => true, 'turnstile.secret_key' => null]);
    }

    /** HIBP answers with BREACHED's suffix (count 42) plus padding rows (count 0, one of them CLEAN's suffix). */
    private function fakeHibp(): void
    {
        $breached = strtoupper(sha1(self::BREACHED));
        $clean = strtoupper(sha1(self::CLEAN));
        Http::fake(['api.pwnedpasswords.com/range/*' => function (HttpRequest $request) use ($breached, $clean) {
            $prefix = substr($request->url(), -5);
            $rows = ['0018A45C4D1DEF81644B54AB7F969B88D65:3'];
            if ($prefix === substr($breached, 0, 5)) {
                $rows[] = substr($breached, 5).':42';
            }
            if ($prefix === substr($clean, 0, 5)) {
                $rows[] = substr($clean, 5).':0';
            }

            return Http::response(implode("\r\n", $rows));
        }]);
    }

    private function signup(string $password, string $email = 'marta@example.test'): TestResponse
    {
        $token = $this->getJson('/signup/form')->assertOk()->json('form_token');
        $this->travel(5)->seconds();

        return $this->postJson('/signup', [
            'name' => 'Marta', 'email' => $email, 'password' => $password, 'form_token' => $token, 'website' => '', 'accept_terms' => true,
        ]);
    }

    #[Test]
    public function signup_rejects_a_breached_password_and_accepts_a_clean_one(): void
    {
        $this->fakeHibp();

        $this->signup(self::BREACHED)->assertStatus(422)->assertJsonValidationErrors(['password' => 'data breach']);
        $this->assertDatabaseMissing('users', ['email' => 'marta@example.test']);

        $this->signup(self::CLEAN)->assertCreated();
        Notification::assertSentTo(User::query()->where('email', 'marta@example.test')->firstOrFail(), VerifyEmail::class);

        Http::assertSent(function (HttpRequest $request) {
            $prefix = substr(strtoupper(sha1(self::CLEAN)), 0, 5);

            return $request->url() === "https://api.pwnedpasswords.com/range/$prefix"
                && $request->hasHeader('Add-Padding', 'true')
                && ! str_contains($request->url(), substr(strtoupper(sha1(self::CLEAN)), 5));
        });
    }

    #[Test]
    public function reset_and_change_reject_a_breached_password(): void
    {
        $this->fakeHibp();
        $user = $this->workspaceUser(['email' => 'marta@example.test', 'password' => Hash::make(self::CLEAN)]);
        $token = Password::broker()->createToken($user);

        $this->postJson('/reset-password', ['email' => 'marta@example.test', 'token' => $token, 'password' => self::BREACHED])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check(self::CLEAN, $user->fresh()->password));

        $this->actingAs($user)->putJson('/account/password', ['current_password' => self::CLEAN, 'password' => self::BREACHED])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->actingAs($user)->putJson('/account/password', ['current_password' => self::CLEAN, 'password' => 'a fresh clean passphrase'])
            ->assertOk();
        $this->assertTrue(Hash::check('a fresh clean passphrase', $user->fresh()->password));
    }

    #[Test]
    public function short_passwords_fail_on_length_without_calling_hibp(): void
    {
        $this->fakeHibp();

        $this->signup('short')->assertStatus(422)->assertJsonValidationErrors('password');
        Http::assertNothingSent();
    }

    #[Test]
    public function hibp_errors_and_timeouts_fail_open_and_log_without_the_password(): void
    {
        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        Http::fake(['api.pwnedpasswords.com/*' => Http::response('oops', 500)]);
        $this->signup(self::BREACHED)->assertCreated();

        auth('web')->logout();
        Http::fake(fn () => throw new ConnectionException('Operation timed out after 3000 milliseconds'));
        $this->signup(self::BREACHED, 'marta2@example.test')->assertCreated();

        $this->assertCount(2, $logged);
        $hash = strtoupper(sha1(self::BREACHED));
        foreach ($logged as $line) {
            $this->assertStringContainsString('fail-open', $line);
            $this->assertStringNotContainsString(self::BREACHED, $line);
            $this->assertStringNotContainsString(substr($hash, 0, 5), strtoupper($line));
        }
    }

    #[Test]
    public function the_check_is_off_when_disabled(): void
    {
        config(['bridge.hibp_enabled' => false]);

        $this->signup(self::BREACHED)->assertCreated();
        Http::assertNothingSent();
    }
}
