<?php

namespace Tests\Feature\MultiUser;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The whole new-user chain with MAIL_MAILER=log, as on staging before real mail: sign-up (terms checkbox, Turnstile off)
 * -> verification mail in the log with an absolute APP_URL link -> verify notice + throttled resend -> own workspace ->
 * first project from onboarding (POST /projects) -> board -> link clicked -> logout / login lands on the boards. Plus the
 * password reset through the logged mail. Synthetic data only.
 */
class SignupToFirstProjectFlowTest extends TestCase
{
    use RefreshDatabase;

    private const APP_URL = 'https://bridge.example.test';

    private string $mailLog;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->mailLog = tempnam(sys_get_temp_dir(), 'bridge-mail-log-');
        config([
            'app.url' => self::APP_URL,
            'turnstile.site_key' => null,
            'turnstile.secret_key' => null,
            'mail.default' => 'log',
            'mail.mailers.log.channel' => 'flow-mail',
            'logging.channels.flow-mail' => ['driver' => 'single', 'path' => $this->mailLog, 'level' => 'debug'],
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->mailLog);
        parent::tearDown();
    }

    /** Every absolute link to $path in the logged mails (decoded text parts). */
    private function loggedLinks(string $path): array
    {
        preg_match_all('#https?://[^\s"<>()\[\]]+'.preg_quote($path, '#').'[^\s"<>()\[\]]*#', (string) file_get_contents($this->mailLog), $m);

        return array_values(array_unique(array_map(fn (string $u) => html_entity_decode($u), $m[0])));
    }

    private function signup(string $email): User
    {
        $token = $this->get('/signup')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Auth/Signup')->where('acceptTerms', false)->where('turnstileSiteKey', null))
            ->inertiaProps('formToken');
        $this->travel(5)->seconds();

        $this->postJson('/signup', [
            'name' => 'Alex Example', 'email' => $email, 'password' => 'quiet-meadow-lantern-42',
            'form_token' => $token, 'website' => '', 'accept_terms' => true,
        ])->assertCreated()->assertJsonPath('user.email_verified', false);

        return User::query()->where('email', $email)->firstOrFail();
    }

    #[Test]
    public function signup_verify_first_project_and_login_again_end_to_end(): void
    {
        // 1. Sign-up: logged in, unverified, terms stored, own workspace (owner, no features).
        $alex = $this->signup('alex@example.test');
        $this->assertAuthenticatedAs($alex);
        $this->assertFalse($alex->hasVerifiedEmail());
        $this->assertNotNull($alex->terms_accepted_at);
        $workspace = $alex->homeWorkspace();
        $this->assertNotNull($workspace);
        $this->assertSame($alex->id, $workspace->owner_user_id);
        $this->assertFalse($workspace->hasFeature('polymarket'));

        // 2. The verification mail is in the log, with one absolute link on APP_URL.
        $links = $this->loggedLinks('/email/verify/');
        $this->assertCount(1, $links);
        $this->assertStringStartsWith(self::APP_URL.'/email/verify/'.$alex->id.'/', $links[0]);
        $this->assertStringContainsString('signature=', $links[0]);

        // 3. Unverified: the verify notice (browser) with a resend that works and is throttled (6 per minute).
        $this->get('/email/verify')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Auth/VerifyEmail')->where('email', 'alex@example.test')->where('resendUrl', '/email/verification-notification')
            ->where('copy.verify.heading', 'Verify your email')
            ->where('copy.verify.tokens_note', 'API tokens for bots and MCP need the verified email.')
            ->where('copy.verify.too_many', 'Too many requests. Wait a minute and try again.')
            ->where('copy.verify.sent', 'A new link is on its way to :email. It works for 60 minutes.')
            ->where('copy.stage.hero', 'One bridge between you and your bots.'));
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/email/verification-notification')->assertOk();
        }
        $this->postJson('/email/verification-notification')->assertStatus(429);
        $this->assertSame(7, substr_count((string) file_get_contents($this->mailLog), 'Subject: Verify your email address'));
        $this->travel(2)->minutes();

        // 4. Onboarding: no project yet; step 1 creates the first project in Alex's workspace; its board opens.
        $this->get('/welcome')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Onboarding')->where('project', null)->where('emailVerified', false)->where('createProjectUrl', fn (string $url) => str_ends_with($url, '/projects')));
        $this->postJson('/projects', ['name' => 'Garden Planner', 'slug' => 'garden-planner', 'ticket_prefix' => 'GP'])
            ->assertCreated()->assertJsonPath('data.slug', 'garden-planner');
        $project = Project::withoutGlobalScopes()->where('slug', 'garden-planner')->firstOrFail();
        $this->assertSame($workspace->id, $project->workspace_id);
        $this->get('/projects/garden-planner')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Board'));

        // 5. Log out; the (latest) mail link verifies without a session and sends the browser to login.
        $this->postJson('/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->assertGuest('web');
        $this->get(end($links) ?: $links[0])->assertRedirect(route('login'));
        $this->assertTrue($alex->fresh()->hasVerifiedEmail());

        // 6. Log in again: straight to the boards, the project is there.
        $this->postJson('/login', ['email' => 'alex@example.test', 'password' => 'quiet-meadow-lantern-42'])
            ->assertOk()->assertJsonPath('redirect', route('portfolio'))->assertJsonPath('user.email_verified', true);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Portfolio')->has('projects', 1)->where('projects.0.slug', 'garden-planner')->where('features.polymarket', false));
        $this->get('/projects/garden-planner')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Board'));
        $this->get('/email/verify')->assertRedirect(route('portfolio'));
    }

    #[Test]
    public function an_unverified_login_goes_to_the_verify_notice_and_guests_go_to_login(): void
    {
        $this->signup('sam@example.test');
        $this->postJson('/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->get('/email/verify')->assertRedirect(route('login'));
        $this->getJson('/email/verify')->assertForbidden()->assertJsonPath('verified', false);
        $this->postJson('/login', ['email' => 'sam@example.test', 'password' => 'quiet-meadow-lantern-42'])
            ->assertOk()->assertJsonPath('redirect', route('verification.notice'));
        $this->get('/email/verify')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Auth/VerifyEmail'));
        // The boards still work unverified (tokens / MCP do not).
        $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Portfolio'));
    }

    #[Test]
    public function password_reset_through_the_logged_mail(): void
    {
        $user = User::factory()->create(['email' => 'robin@example.test', 'password' => 'old-river-stone-88']);

        $this->postJson('/forgot-password', ['email' => 'robin@example.test'])->assertOk();
        $links = $this->loggedLinks('/reset-password/');
        $this->assertCount(1, $links);
        $this->assertStringStartsWith(self::APP_URL.'/reset-password/', $links[0]);
        $token = (string) preg_replace('#^.*/reset-password/([^?]+)\?.*$#', '$1', $links[0]);

        $this->get($links[0])->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Auth/ResetPassword')->where('valid', true)->where('email', 'robin@example.test'));
        $this->postJson('/reset-password', [
            'email' => 'robin@example.test', 'token' => $token,
            'password' => 'new-harbour-kite-51', 'password_confirmation' => 'new-harbour-kite-51',
        ])->assertOk();

        $this->postJson('/login', ['email' => 'robin@example.test', 'password' => 'old-river-stone-88'])->assertStatus(422);
        $this->postJson('/login', ['email' => 'robin@example.test', 'password' => 'new-harbour-kite-51'])->assertOk();
        $this->assertAuthenticatedAs($user->fresh());
    }
}
