<?php

namespace Tests\Feature\MultiUser;

use App\Http\Controllers\Auth\PasswordController;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Taz pre-prod pass #3: /forgot-password is throttled per email (any IP) as well as per IP, same answer either way. */
class ForgotPasswordThrottleTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC = 'If that email has an account, a reset link is on its way.';

    private function ask(string $email, string $ip): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/forgot-password', ['email' => $email]);
    }

    #[Test]
    public function the_same_email_from_many_ips_gets_at_most_the_limit_of_mails_and_the_same_answer(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'marta@example.test']);

        for ($i = 1; $i <= PasswordController::FORGOT_PER_EMAIL + 3; $i++) {
            $this->travel(61)->seconds(); // past the broker's own per-user 60 s throttle
            $this->ask($i % 2 ? 'marta@example.test' : ' MARTA@example.test', "203.0.113.$i")
                ->assertOk()->assertExactJson(['message' => self::GENERIC]);
        }
        Notification::assertSentToTimes($user, ResetPassword::class, PasswordController::FORGOT_PER_EMAIL);

        $this->travel(PasswordController::FORGOT_DECAY + 1)->seconds();
        $this->ask('marta@example.test', '203.0.113.99')->assertOk()->assertExactJson(['message' => self::GENERIC]);
        Notification::assertSentToTimes($user, ResetPassword::class, PasswordController::FORGOT_PER_EMAIL + 1);
    }

    #[Test]
    public function an_unknown_email_gets_the_same_answer_throttled_or_not(): void
    {
        Notification::fake();
        for ($i = 1; $i <= PasswordController::FORGOT_PER_EMAIL + 2; $i++) {
            $this->ask('nobody@example.test', "198.51.100.$i")->assertOk()->assertExactJson(['message' => self::GENERIC]);
        }
        Notification::assertNothingSent();
    }

    #[Test]
    public function the_per_ip_throttle_still_applies(): void
    {
        Notification::fake();
        for ($i = 1; $i <= 6; $i++) {
            $this->ask("user$i@example.test", '192.0.2.7')->assertOk();
        }
        $this->ask('user7@example.test', '192.0.2.7')->assertStatus(429);
    }
}
