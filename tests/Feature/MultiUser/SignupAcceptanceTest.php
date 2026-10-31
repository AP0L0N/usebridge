<?php

namespace Tests\Feature\MultiUser;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Sign-up acceptance: one required, not pre-ticked checkbox (terms + age 18), stored with the terms version. */
class SignupAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::preventStrayRequests();
        config(['turnstile.site_key' => null, 'turnstile.secret_key' => null]);
    }

    private function signup(array $extra, string $email = 'alex@example.test'): TestResponse
    {
        $token = $this->getJson('/signup/form')->assertOk()->json('form_token');
        $this->travel(5)->seconds();

        return $this->postJson('/signup', [
            'name' => 'Alex Example', 'email' => $email, 'password' => 'quiet-meadow-lantern-42', 'form_token' => $token, 'website' => '', ...$extra,
        ]);
    }

    #[Test]
    public function the_checkbox_is_unticked_by_default_with_the_ui_copy(): void
    {
        $this->get('/signup')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Auth/Signup')
            ->where('acceptTerms', false)
            ->where('acceptance.checkbox', "I'm at least 18 years old and I agree to the :terms. I've read the :privacy.")
            ->where('acceptance.checkbox', __('legal.signup.checkbox'))
            ->where('acceptance.termsLink', 'Terms of Use')
            ->where('acceptance.privacyLink', 'Privacy Policy')
            ->where('acceptance.termsUrl', '/terms')
            ->where('acceptance.privacyUrl', '/privacy')
            ->where('acceptance.smallPrint', 'Bridge is for adults (18+) only. Please keep sensitive data off your boards: nothing about health, religion or beliefs, biometrics or similar sensitive matters, and nothing about criminal records or offences. See the :terms and the :privacy.'));
        $this->assertNotEmpty(__('legal.signup.small_print', [], 'sl'));
        $this->assertNotSame(__('legal.signup.small_print', [], 'en'), __('legal.signup.small_print', [], 'sl'));
    }

    #[Test]
    public function a_missing_or_false_checkbox_is_refused_with_422_and_creates_nothing(): void
    {
        foreach ([[], ['accept_terms' => false], ['accept_terms' => '0'], ['accept_terms' => 'no']] as $extra) {
            $this->signup($extra)->assertStatus(422)->assertJsonValidationErrors('accept_terms');
            $this->assertGuest();
        }
        $this->assertNull(User::query()->where('email', 'alex@example.test')->first());
    }

    #[Test]
    public function acceptance_stores_both_timestamps_and_the_configured_terms_version(): void
    {
        config(['bridge.terms_version' => '2026-11-test']);
        $this->freezeSecond();

        $this->signup(['accept_terms' => true])->assertCreated();
        $user = User::query()->where('email', 'alex@example.test')->firstOrFail();
        $this->assertSame('2026-11-test', $user->terms_version);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertTrue($user->terms_accepted_at->equalTo(now()));
        $this->assertTrue($user->age_confirmed_at->equalTo($user->terms_accepted_at));
    }

    #[Test]
    public function the_default_terms_version_comes_from_config(): void
    {
        $this->assertSame('2026-10-31', config('bridge.terms_version'));
        $this->signup(['accept_terms' => 'on'])->assertCreated();
        $this->assertSame('2026-10-31', User::query()->where('email', 'alex@example.test')->value('terms_version'));
    }

    #[Test]
    public function existing_users_are_left_untouched(): void
    {
        $existing = User::factory()->create(['email' => 'robin@example.test']);
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();

        $this->signup(['accept_terms' => true])->assertCreated();

        foreach ([$existing, $owner] as $user) {
            $user = $user->fresh();
            $this->assertNull($user->terms_accepted_at);
            $this->assertNull($user->age_confirmed_at);
            $this->assertNull($user->terms_version);
        }
    }
}
