<?php

namespace Tests\Feature\MultiUser;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** POST /signup: own workspace + verification mail; 5 per IP/hour, honeypot, minimum fill time, Turnstile (faked). */
class SignupTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        Notification::fake();
        Http::preventStrayRequests();
        config(['turnstile.site_key' => null, 'turnstile.secret_key' => null]);
    }

    /** A form fetched now and submitted $after seconds later. */
    private function signup(array $data = [], int $after = 5, string $ip = '10.0.0.1'): TestResponse
    {
        $token = $this->getJson('/signup/form')->assertOk()->json('form_token');
        $this->travel($after)->seconds();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/signup', [
            'name' => 'Marta', 'email' => 'marta@example.test', 'password' => 'correct horse battery',
            'form_token' => $token, 'website' => '', 'accept_terms' => true, ...$data,
        ]);
    }

    #[Test]
    public function the_form_says_whether_turnstile_is_on(): void
    {
        $this->getJson('/signup/form')->assertOk()->assertJsonPath('turnstile_site_key', null)->assertJsonPath('min_password', 10);
        config(['turnstile.site_key' => 'site-key']);
        $this->getJson('/signup/form')->assertJsonPath('turnstile_site_key', 'site-key');
    }

    #[Test]
    public function signing_up_creates_an_unverified_user_with_their_own_workspace_and_logs_in(): void
    {
        $this->signup()->assertCreated()->assertJsonPath('user.email_verified', false);

        $user = User::query()->where('email', 'marta@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertFalse($user->is_admin);
        $this->assertSame('owner', Workspace::query()->where('owner_user_id', $user->id)->firstOrFail()->users()->find($user->id)->pivot->role);
        Notification::assertSentTo($user, VerifyEmail::class);
        Http::assertNothingSent(); // no secret: Turnstile skipped

        // Boards work unverified; tokens do not.
        $this->getJson('/')->assertOk();
        $this->postJson('/account/tokens', ['name' => 'x', 'scope' => 'read'])->assertForbidden();
    }

    #[Test]
    public function a_taken_email_and_a_short_password_are_field_errors(): void
    {
        User::factory()->create(['email' => 'marta@example.test']);
        $this->signup()->assertStatus(422)->assertJsonValidationErrors('email');
        $this->signup(['email' => 'new@example.test', 'password' => 'nine char'])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    #[Test]
    public function the_honeypot_and_the_fill_time_stop_bots_even_without_turnstile(): void
    {
        $this->signup(['website' => 'http://spam.example'])->assertStatus(422)->assertJsonValidationErrors('form');
        $this->signup([], after: 1)->assertStatus(422)->assertJsonValidationErrors('form');
        $this->signup(['form_token' => 'forged'])->assertStatus(422)->assertJsonValidationErrors('form');
        $this->signup([], after: 86400 + 10)->assertStatus(422)->assertJsonValidationErrors('form');
        $this->assertSame(0, User::query()->where('email', 'marta@example.test')->count());
    }

    #[Test]
    public function five_sign_ups_per_ip_and_hour(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->flushSession();
            $this->signup(['email' => "u$i@example.test"])->assertCreated();
        }
        $this->signup(['email' => 'u5@example.test'])->assertStatus(429);
        $this->signup(['email' => 'u6@example.test'], ip: '10.0.0.2')->assertCreated();
        $this->travel(61)->minutes();
        $this->signup(['email' => 'u7@example.test'])->assertCreated();
    }

    #[Test]
    public function with_a_secret_turnstile_is_verified_server_side_and_fails_closed(): void
    {
        config(['turnstile.secret_key' => 'secret']);
        Http::fake([self::VERIFY => Http::sequence()
            ->push(['success' => false, 'error-codes' => ['invalid-input-response']])
            ->push(['success' => true])
            ->pushStatus(500)]);

        $this->signup(['cf-turnstile-response' => 'bad'])->assertStatus(422)->assertJsonValidationErrors('turnstile');
        $this->signup(['email' => 'none@example.test'])->assertStatus(422)->assertJsonValidationErrors('turnstile'); // missing: no call
        $this->signup(['cf-turnstile-response' => 'good'])->assertCreated();
        $this->flushSession();
        $this->signup(['email' => 'down@example.test', 'cf-turnstile-response' => 'x'])->assertStatus(422);

        Http::assertSentCount(3);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::VERIFY && $r['secret'] === 'secret'
            && $r['response'] === 'good' && $r['remoteip'] === '10.0.0.1');
    }

    #[Test]
    public function a_turnstile_network_error_refuses_the_sign_up(): void
    {
        config(['turnstile.secret_key' => 'secret']);
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->signup(['cf-turnstile-response' => 'x'])->assertStatus(422)->assertJsonValidationErrors('turnstile');
        $this->assertSame(0, User::query()->where('email', 'marta@example.test')->count());
    }
}
