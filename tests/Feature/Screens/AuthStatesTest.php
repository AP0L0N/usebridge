<?php

namespace Tests\Feature\Screens;

use App\Models\User;
use App\Services\Auth\AccountService;
use App\Services\Auth\SignupBotCheck;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Figma "Auth" states (sign up: default / errors / loading / taken; log in: default / wrong / locked / loading;
 * reset: request / sent / set new / expired / success): the Inertia component + props each state is drawn from, the
 * backend signals behind them, and the copy (lang/en/auth_ui.php) incl. the fixes over the frames.
 */
class AuthStatesTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'garden-planner-27';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        Notification::fake();
        config(['turnstile.site_key' => null, 'turnstile.secret_key' => null]);
    }

    private function signup(array $data): TestResponse
    {
        $token = $this->get('/signup')->inertiaProps('formToken');
        $this->travel(5)->seconds();

        return $this->postJson('/signup', [
            'name' => 'Sam Rivera', 'email' => 'sam@example.test', 'password' => self::PASSWORD,
            SignupBotCheck::HONEYPOT => '', 'form_token' => $token, 'accept_terms' => true, ...$data,
        ]);
    }

    // ---- Stage + copy -------------------------------------------------------------------------------------------

    #[Test]
    public function every_auth_screen_gets_the_copy_with_the_sample_stage(): void
    {
        foreach (['/signup' => 'Auth/Signup', '/login' => 'Auth/Login', '/forgot-password' => 'Auth/ForgotPassword', '/reset-password/x' => 'Auth/ResetPassword'] as $url => $component) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p
                ->component($component)
                ->where('copy.stage.hero', 'One bridge between you and your bots.')
                ->where('copy.stage.subline', 'Your projects, boards and bots, in one place.')
                ->where('copy.stage.sample', 'Sample data')
                ->where('copy.topbar.sample', 'Sample data'));
        }
    }

    #[Test]
    public function the_activity_card_has_the_fixed_second_row_and_no_token_count(): void
    {
        $stage = $this->get('/signup')->inertiaProps('copy.stage');

        $this->assertSame([
            ['kind' => 'agent', 'who' => 'via MCP · Ava', 'what' => 'moved Garden planner → Doing'],
            ['kind' => 'agent', 'who' => 'via MCP · Ava', 'what' => 'moved GP-14 → Waiting on you'],
            ['kind' => 'you', 'who' => 'you · Sam Rivera', 'what' => 'approved 2 tickets'],
        ], $stage['activity']);
        $card = json_encode($stage['activity'], JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression('/token|\btok\b|\d+(\.\d+)?\s*k\b/i', $card);
        // Labelled as sample data on the stage (shown uppercase: SAMPLE DATA) and on the narrow top bar.
        $this->assertSame('Activity · sample', $stage['activity_label']);
        $this->assertStringContainsString('text-transform: uppercase', (string) file_get_contents(resource_path('css/auth.css')));
    }

    #[Test]
    public function the_stage_top_bar_and_favicon_use_the_span_brand_files(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">', $html);
        $this->assertStringContainsString('<link rel="apple-touch-icon" href="/brand/favicon-180.png">', $html);
        foreach (['favicon.svg', 'favicon-16.png', 'favicon-32.png', 'favicon-180.png', 'mark.svg', 'lockup-dark.svg', 'lockup-light.svg'] as $file) {
            $this->assertFileExists(public_path("brand/$file"));
        }
        // The Span mark: the arc + lime dot of the brand files, in the favicon and the dark lockup (Stage + GuestTopBar).
        $this->assertStringContainsString('#C8F135', (string) file_get_contents(public_path('brand/favicon.svg')));
        $this->assertStringContainsString('/brand/lockup-dark.svg', (string) file_get_contents(resource_path('js/Components/Auth/Wordmark.tsx')));
    }

    // ---- Sign up ------------------------------------------------------------------------------------------------

    #[Test]
    public function signup_default_has_the_new_subtitle_and_the_unticked_terms_instead_of_the_frame_line(): void
    {
        $res = $this->get('/signup')->assertInertia(fn (Assert $p) => $p
            ->component('Auth/Signup')
            ->where('copy.signup.heading', 'Create your account')
            ->where('copy.signup.subtitle', 'Track your own projects on a board, and let your bots keep them up to date.')
            ->where('copy.signup.name_hint', 'Shown on your boards and to your bots.')
            ->where('acceptTerms', false)
            ->where('acceptance.checkbox', "I'm at least 18 years old and I agree to the :terms. I've read the :privacy.")
            ->has('acceptance.smallPrint'));

        // The frame's "By creating an account you agree to …" line is replaced by the checkbox + small print.
        $this->assertStringNotContainsString('By creating an account', $res->getContent());
        $this->assertStringNotContainsString('Grok bot', $res->getContent());
    }

    #[Test]
    public function signup_errors_are_one_line_per_field_in_the_screen_wording(): void
    {
        $this->signup(['name' => ''])->assertStatus(422)->assertExactJson([
            'message' => 'Enter your name',
            'errors' => ['name' => ['Enter your name']],
        ]);

        $res = $this->signup(['email' => 'not-an-email'])->assertStatus(422)->assertJsonPath('email_taken', false);
        $this->assertSame(['Enter an email like name@example.com'], $res->json('errors.email'));

        $res = $this->signup(['password' => 'short'])->assertStatus(422);
        $this->assertCount(1, $res->json('errors.password'));

        $copy = $this->get('/signup')->inertiaProps('copy');
        $this->assertSame('Enter your name', $copy['errors']['name_required']);
        $this->assertSame('Enter an email like name@example.com', $copy['errors']['email_invalid']);
        $this->assertSame('Use at least :min characters for your password', $copy['errors']['password_short']);
        $this->assertSame('Fix :count things to continue', $copy['common']['summary_many']);
    }

    #[Test]
    public function each_field_renders_only_its_own_error_line_and_no_generic_email_hint(): void
    {
        $field = (string) file_get_contents(resource_path('js/Components/Auth/TextField.tsx'));
        // One message line per field: the error replaces the hint (never both, never a second line).
        $this->assertSame(1, substr_count($field, 'auth-msg auth-msg--error" id={`${id}-err`}'));
        $this->assertStringContainsString("{error\n        ? <p className=\"auth-msg auth-msg--error\"", $field);

        // The Figma input's default "Enter a valid email address." never appears anywhere.
        foreach ([resource_path('js'), lang_path()] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $this->assertStringNotContainsString('Enter a valid email address', (string) file_get_contents($file->getPathname()), $file->getPathname());
            }
        }
    }

    #[Test]
    public function signup_loading_state_copy(): void
    {
        $this->get('/signup')->assertInertia(fn (Assert $p) => $p
            ->where('copy.signup.submit', 'Create account')
            ->where('copy.signup.busy', 'Creating account…')
            ->where('copy.signup.busy_note', 'Creating your account, please wait.'));
    }

    #[Test]
    public function signup_with_a_taken_email_flags_email_taken_on_the_email_field(): void
    {
        User::factory()->create(['email' => 'sam@example.test']);

        $res = $this->signup([])->assertStatus(422)->assertJsonPath('email_taken', true);
        $this->assertSame(['email' => ['This email already has an account']], $res->json('errors'));

        $this->get('/signup')->assertInertia(fn (Assert $p) => $p
            ->where('copy.signup.taken', ":email already has an account. :login, or :reset if you've forgotten it.")
            ->where('copy.signup.taken_login', 'Log in instead'));
    }

    // ---- Log in -------------------------------------------------------------------------------------------------

    #[Test]
    public function login_default_and_loading_copy(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Auth/Login')
            ->where('copy.login.heading', 'Log in')
            ->where('copy.login.subtitle', 'Your projects, boards and bots, in one place.')
            ->where('copy.login.remember', 'Keep me logged in on this device')
            ->where('copy.login.forgot', 'Forgot password?')
            ->where('copy.login.busy', 'Logging in…')
            ->where('copy.login.busy_note', 'Logging in, please wait.'));
    }

    #[Test]
    public function login_wrong_credentials_answers_tries_left(): void
    {
        $this->workspaceUser(['email' => 'sam@example.test', 'password' => Hash::make(self::PASSWORD)]);

        $this->postJson('/login', ['email' => 'sam@example.test', 'password' => 'nope'])->assertStatus(422)->assertJsonPath('tries_left', 4);
        $this->get('/login')->assertInertia(fn (Assert $p) => $p
            ->where('copy.login.wrong', "That email and password don't match. Check both and try again.")
            ->where('copy.login.tries_left_many', ':count tries left before a 15-minute pause.'));
    }

    #[Test]
    public function login_locked_answers_the_seconds_left_from_the_lock(): void
    {
        $this->workspaceUser(['email' => 'sam@example.test', 'password' => Hash::make(self::PASSWORD)]);
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/login', ['email' => 'sam@example.test', 'password' => 'nope'])->assertStatus(422);
        }

        $this->postJson('/login', ['email' => 'sam@example.test', 'password' => 'nope'])->assertStatus(429)
            ->assertJsonPath('retry_after', 15 * 60)
            ->assertJsonPath('locked_until', Carbon::now()->addMinutes(15)->toIso8601String());

        // Counting down: 6 min 20 s later even the right password is refused, with 520 s left.
        $this->travel(380)->seconds();
        $this->postJson('/login', ['email' => 'sam@example.test', 'password' => self::PASSWORD])->assertStatus(429)->assertJsonPath('retry_after', 520);

        // Unknown emails get the same signal.
        for ($i = 0; $i < 5; $i++) {
            $last = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.(20 + $i)])->postJson('/login', ['email' => 'nobody@example.test', 'password' => 'nope']);
        }
        $last->assertStatus(429)->assertJsonPath('retry_after', 900);

        $this->get('/login')->assertInertia(fn (Assert $p) => $p
            ->where('copy.login.locked', 'Too many attempts. Try again in :wait. Or :reset now; the link works right away.')
            ->where('copy.login.locked_button', 'Log in (paused for :wait)')
            ->where('copy.login.wait_minutes', ':count minutes')
            ->where('copy.login.wait_seconds', ':count seconds'));
    }

    #[Test]
    public function login_ip_throttle_also_answers_seconds_and_until(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $res = $this->postJson('/login', ['email' => "user$i@example.test", 'password' => 'nope']);
            if ($res->status() === 429) {
                break;
            }
        }
        $res->assertStatus(429)->assertJsonStructure(['message', 'retry_after', 'locked_until']);
        $this->assertGreaterThan(0, $res->json('retry_after'));
    }

    // ---- Reset --------------------------------------------------------------------------------------------------

    #[Test]
    public function reset_request_screen(): void
    {
        $this->get('/forgot-password')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Auth/ForgotPassword')
            ->where('copy.forgot.heading', 'Reset your password')
            ->where('copy.forgot.submit', 'Send reset link'));
    }

    #[Test]
    public function reset_sent_answers_the_same_for_known_and_unknown_emails(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.test']);

        $known = $this->postJson('/forgot-password', ['email' => 'sam@example.test'])->assertOk()->json();
        $unknown = $this->postJson('/forgot-password', ['email' => 'nobody@example.test'])->assertOk()->json();
        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, ResetPassword::class);

        $this->get('/forgot-password')->assertInertia(fn (Assert $p) => $p
            ->where('copy.forgot.sent_heading', 'Check your email')
            ->where('copy.forgot.sent_body', 'If :email has a Bridge account, a reset link is on its way. It works for 60 minutes.')
            ->where('copy.forgot.send_again', 'Send it again'));
    }

    #[Test]
    public function reset_set_new_for_a_live_token(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.test']);
        $token = Password::broker()->createToken($user);

        $this->get("/reset-password/$token?email=sam@example.test")->assertInertia(fn (Assert $p) => $p
            ->component('Auth/ResetPassword')
            ->where('valid', true)
            ->where('minPassword', AccountService::MIN_PASSWORD)
            ->where('copy.reset.heading', 'Set a new password')
            ->where('copy.strength.match', 'Passwords match'));
    }

    #[Test]
    public function reset_expired_for_an_old_used_or_wrong_token(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.test']);
        $token = Password::broker()->createToken($user);

        $this->get('/reset-password/wrong?email=sam@example.test')->assertInertia(fn (Assert $p) => $p->where('valid', false));

        // Older than 60 minutes: the expired screen on load, and POST answers expired too.
        $this->travel(61)->minutes();
        $this->get("/reset-password/$token?email=sam@example.test")->assertInertia(fn (Assert $p) => $p
            ->where('valid', false)
            ->where('copy.reset.expired_heading', 'This link has expired')
            ->where('copy.reset.expired_action', 'Send a new link'));
        $this->postJson('/reset-password', ['email' => 'sam@example.test', 'token' => $token, 'password' => 'a-brand-new-pass-42'])
            ->assertStatus(422)->assertJsonPath('expired', true);
    }

    #[Test]
    public function reset_success_changes_the_password_and_uses_the_token_once(): void
    {
        $user = $this->workspaceUser(['email' => 'sam@example.test']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/reset-password', ['email' => 'sam@example.test', 'token' => $token, 'password' => 'a-brand-new-pass-42'])->assertOk();
        $this->assertTrue(Hash::check('a-brand-new-pass-42', $user->fresh()->password));
        $this->get("/reset-password/$token?email=sam@example.test")->assertInertia(fn (Assert $p) => $p->where('valid', false));

        $this->get('/login')->assertInertia(fn (Assert $p) => $p
            ->where('copy.reset.success_heading', 'Password updated')
            ->where('copy.login.status.password-reset', 'Password updated. Log in with your new password.'));
    }
}
