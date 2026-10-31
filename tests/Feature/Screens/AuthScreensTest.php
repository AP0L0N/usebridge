<?php

namespace Tests\Feature\Screens;

use App\Models\User;
use App\Services\Auth\AccountService;
use App\Services\Auth\SignupBotCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Logged-out screens (docs/MULTI-USER-PLAN.md §8): Inertia component + props; the writes stay on the JSON routes. */
class AuthScreensTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_land_on_login_which_links_the_workspace_gate(): void
    {
        $this->get('/')->assertOk()->assertViewIs('landing'); // the public landing page, no longer a redirect
        $this->get('/usage')->assertRedirect('/login');

        $this->get('/login')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Auth/Login')
            ->where('gateAvailable', true)
            ->where('auth.user', null)
            ->where('features.polymarket', false)
            ->where('features.admin', false));

        // AUTH-1 keeps working for the existing workspace until Tilen retires it (back to the page asked for).
        $this->post('/gate', ['password' => config('bridge.gate_password')])->assertRedirect(route('usage'));
        $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Portfolio')->where('auth.user', null));
    }

    #[Test]
    public function login_is_hidden_without_a_gate_password_and_logged_in_users_skip_it(): void
    {
        config(['bridge.gate_password' => '']);
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('gateAvailable', false));

        $this->actingAs($this->workspaceUser())->get('/login')->assertRedirect(route('portfolio'));
        $this->get('/signup')->assertRedirect(route('portfolio'));
    }

    #[Test]
    public function signup_without_turnstile_keys_has_only_the_form_token(): void
    {
        config(['turnstile.site_key' => null]);

        $props = $this->get('/signup')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Auth/Signup')
            ->where('turnstileSiteKey', null)
            ->where('minPassword', AccountService::MIN_PASSWORD)
            ->has('formToken'))->inertiaProps();

        $this->assertSame(['formToken', 'turnstileSiteKey', 'minPassword'], array_values(array_intersect(array_keys($props), ['formToken', 'turnstileSiteKey', 'minPassword'])));
        // The form token is the signed start time SignupBotCheck accepts (never a secret).
        $this->assertIsNumeric(Crypt::decryptString($props['formToken']));
    }

    #[Test]
    public function signup_passes_the_turnstile_site_key_but_never_the_secret(): void
    {
        config(['turnstile.site_key' => 'site-key-public', 'turnstile.secret_key' => 'secret-never-sent']);

        $res = $this->get('/signup')->assertInertia(fn (Assert $p) => $p->where('turnstileSiteKey', 'site-key-public'));
        $this->assertStringNotContainsString('secret-never-sent', $res->getContent());
    }

    #[Test]
    public function the_signup_form_token_from_the_page_is_accepted_by_post_signup(): void
    {
        Notification::fake();
        $token = $this->get('/signup')->inertiaProps('formToken');
        $this->travel(5)->seconds();

        $this->postJson('/signup', [
            'name' => 'Sam Rivera', 'email' => 'sam@example.test', 'password' => 'garden-planner-27',
            SignupBotCheck::HONEYPOT => '', 'form_token' => $token,
        ])->assertCreated();
        $this->assertAuthenticated();
    }

    #[Test]
    public function forgot_and_reset_screens(): void
    {
        $this->get('/forgot-password')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Auth/ForgotPassword'));

        $user = User::factory()->create(['email' => 'marta@example.test']);
        $token = Password::broker()->createToken($user);

        $this->get('/reset-password/'.$token.'?email=marta@example.test')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Auth/ResetPassword')
            ->where('token', $token)
            ->where('email', 'marta@example.test')
            ->where('valid', true)
            ->where('minPassword', AccountService::MIN_PASSWORD));

        // Wrong token and unknown email look the same: expired.
        $this->get('/reset-password/nope?email=marta@example.test')->assertInertia(fn (Assert $p) => $p->where('valid', false));
        $this->get('/reset-password/'.$token.'?email=nobody@example.test')->assertInertia(fn (Assert $p) => $p->where('valid', false));
    }

    #[Test]
    public function the_reset_mail_link_opens_the_reset_screen(): void
    {
        $user = User::factory()->create(['email' => 'marta@example.test']);
        $url = (new \Illuminate\Auth\Notifications\ResetPassword('tok123'))->toMail($user)->actionUrl;

        $this->assertStringEndsWith('/reset-password/tok123?email=marta%40example.test', $url);
        $this->get(parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))
            ->assertInertia(fn (Assert $p) => $p->component('Auth/ResetPassword')->where('email', 'marta@example.test'));
    }

    #[Test]
    public function a_disabled_user_mid_session_lands_on_login_with_the_reason(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user)->get('/')->assertOk();
        $user->forceFill(['disabled_at' => now()])->save();

        $this->get('/')->assertRedirect('/login')->assertSessionHas('error', 'This account is disabled.');
    }

    #[Test]
    public function the_verification_link_in_a_browser_redirects_with_a_flash(): void
    {
        $user = $this->workspaceUser(['email_verified_at' => null]);
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->actingAs($user)->get($url)->assertRedirect('/account/tokens')->assertSessionHas('success');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }
}
