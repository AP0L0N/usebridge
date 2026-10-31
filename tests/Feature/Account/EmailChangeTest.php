<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Notifications\EmailChangeConfirmed;
use App\Notifications\EmailChangeRequested;
use App\Notifications\VerifyNewEmail;
use App\Services\Auth\AccountService;
use App\Services\Auth\EmailChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** PATCH /account email change: pending until the signed link to the new address is opened (EmailChange). */
class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'garden-planner-27';

    private User $sam;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        config(['app.url' => 'https://bridge.example.test']);
        Notification::fake();
        $this->sam = app(AccountService::class)->register('Sam Rivera', 'sam@example.com', self::PASSWORD);
        $this->sam->markEmailAsVerified();
        $this->sam = $this->sam->fresh();
    }

    private function change(string $email, ?string $password = self::PASSWORD)
    {
        return $this->actingAs($this->sam)->patchJson('/account', [
            'name' => 'Sam Rivera', 'timezone' => 'Europe/Ljubljana', 'email' => $email, 'current_password' => $password,
        ]);
    }

    /** The link from the mail to the new address (captured from the fake, never from a prop or the log). */
    private function link(): string
    {
        $url = null;
        Notification::assertSentOnDemand(VerifyNewEmail::class, function (VerifyNewEmail $n, array $channels, AnonymousNotifiable $to) use (&$url) {
            $url = $n->url();

            return true;
        });

        return (string) $url;
    }

    private function login(string $email)
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->postJson('/login', ['email' => $email, 'password' => self::PASSWORD]);
    }

    #[Test]
    public function a_new_email_is_pending_and_the_old_one_keeps_logging_in(): void
    {
        $this->change('sam.rivera@example.com')->assertOk()
            ->assertJsonPath('user.email', 'sam@example.com')
            ->assertJsonPath('user.pending_email', 'sam.rivera@example.com')
            ->assertJsonPath('email_change_started', true);

        $fresh = $this->sam->fresh();
        $this->assertSame(['sam@example.com', 'sam.rivera@example.com'], [$fresh->email, $fresh->pending_email]);
        $this->assertNotNull($fresh->pending_email_at);

        // Link to the new address (rooted at APP_URL), notice with the masked new address to the old one.
        Notification::assertSentOnDemand(VerifyNewEmail::class, fn ($n, $c, AnonymousNotifiable $to) => $to->routes['mail'] === 'sam.rivera@example.com');
        Notification::assertSentTo($fresh, EmailChangeRequested::class, function (EmailChangeRequested $n) use ($fresh) {
            $mail = $n->toMail($fresh);

            return $n->maskedNew === 's••••@example.com' && ! str_contains(implode(' ', $mail->introLines), 'sam.rivera@') && $mail->actionUrl === null;
        });
        $this->assertStringStartsWith('https://bridge.example.test/account/email/verify/'.$this->sam->id.'/', $this->link());

        $this->login('sam@example.com')->assertOk();
        $this->login('sam.rivera@example.com')->assertStatus(422);
    }

    #[Test]
    public function the_link_swaps_the_email_and_ends_other_sessions(): void
    {
        $this->change('sam.rivera@example.com')->assertOk();
        DB::table('sessions')->insert(['id' => 'phone', 'user_id' => $this->sam->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'Test', 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        $link = $this->link();

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get($link)->assertRedirect('/login')->assertSessionHas('success');

        $fresh = $this->sam->fresh();
        $this->assertSame('sam.rivera@example.com', $fresh->email);
        $this->assertNull($fresh->pending_email);
        $this->assertNull($fresh->pending_email_at);
        $this->assertTrue($fresh->hasVerifiedEmail());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->sam->id)->count());
        Notification::assertSentOnDemand(EmailChangeConfirmed::class, fn ($n, $c, AnonymousNotifiable $to) => $to->routes['mail'] === 'sam@example.com');

        $this->login('sam@example.com')->assertStatus(422);
        $this->login('sam.rivera@example.com')->assertOk();
        // A link works once.
        $this->get($link)->assertRedirect()->assertSessionHas('error');
    }

    #[Test]
    public function opening_the_link_while_logged_in_keeps_this_device(): void
    {
        $this->change('sam.rivera@example.com')->assertOk();
        DB::table('sessions')->insert(['id' => 'phone', 'user_id' => $this->sam->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'Test', 'payload' => '', 'last_activity' => now()->getTimestamp()]);

        $this->actingAs($this->sam)->get($this->link())->assertRedirect('/account')->assertSessionHas('success');

        $this->assertSame(0, DB::table('sessions')->where('id', 'phone')->count());
        $this->assertSame('sam.rivera@example.com', $this->sam->fresh()->email);
    }

    #[Test]
    public function the_current_password_is_needed_and_taken_emails_are_refused(): void
    {
        User::factory()->create(['email' => 'ava@example.com']);

        $this->change('sam.rivera@example.com', null)->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->change('sam.rivera@example.com', 'wrong password')->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->change('Ava@Example.com')->assertStatus(422)->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', __('account.email.taken'));
        $this->change('not-an-email')->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertNull($this->sam->fresh()->pending_email);
        Notification::assertNotSentTo($this->sam->fresh(), EmailChangeRequested::class);
        Notification::assertSentOnDemandTimes(VerifyNewEmail::class, 0);
    }

    #[Test]
    public function expired_tampered_and_stale_links_are_refused(): void
    {
        $this->change('sam.rivera@example.com')->assertOk();
        $link = $this->link();

        // tampered signature / hash
        $this->get($link.'x')->assertRedirect()->assertSessionHas('error');
        $tampered = URL::temporarySignedRoute('account.email.verify', now()->addHour(), ['id' => $this->sam->id, 'hash' => str_repeat('a', 64)]);
        $this->get($tampered)->assertRedirect()->assertSessionHas('error');
        $this->assertSame('sam@example.com', $this->sam->fresh()->email);

        // expired
        Carbon::setTestNow(now()->addMinutes(EmailChange::LINK_MINUTES + 1));
        $this->get($link)->assertRedirect()->assertSessionHas('error');
        $this->assertSame('sam@example.com', $this->sam->fresh()->email);

        // stale: a link for an older pending address fails after a new request
        Carbon::setTestNow('2026-10-31 13:30:00');
        Notification::fake();
        $this->change('sam.r@example.com')->assertOk();
        $this->getJson($link)->assertForbidden();
        $this->assertSame('sam@example.com', $this->sam->fresh()->email);
        $this->getJson($this->link())->assertOk();
        $this->assertSame('sam.r@example.com', $this->sam->fresh()->email);
    }

    #[Test]
    public function an_address_taken_meanwhile_fails_cleanly(): void
    {
        $this->change('sam.rivera@example.com')->assertOk();
        User::factory()->create(['email' => 'sam.rivera@example.com']);

        $this->getJson($this->link())->assertForbidden()->assertJsonPath('message', __('account.email.link_taken'));
        $fresh = $this->sam->fresh();
        $this->assertSame('sam@example.com', $fresh->email);
        $this->assertNull($fresh->pending_email);
    }

    #[Test]
    public function cancel_keeps_the_old_email_and_kills_the_link(): void
    {
        $this->change('sam.rivera@example.com')->assertOk();
        $link = $this->link();

        $this->actingAs($this->sam)->deleteJson('/account/email/pending')->assertOk()->assertJsonPath('user.pending_email', null);

        $this->assertNull($this->sam->fresh()->pending_email);
        $this->getJson($link)->assertForbidden();
        $this->assertSame('sam@example.com', $this->sam->fresh()->email);
    }

    #[Test]
    public function resend_mails_the_pending_address_and_is_throttled(): void
    {
        $this->actingAs($this->sam)->postJson('/account/email/resend')->assertStatus(422);
        $this->change('sam.rivera@example.com')->assertOk();

        $this->actingAs($this->sam)->postJson('/account/email/resend')->assertOk();
        $this->actingAs($this->sam)->postJson('/account/email/resend')->assertOk();
        $this->actingAs($this->sam)->postJson('/account/email/resend')->assertStatus(429);
        Notification::assertSentOnDemandTimes(VerifyNewEmail::class, 3);
    }

    #[Test]
    public function props_expose_the_pending_email_but_never_a_link(): void
    {
        $this->change('sam.rivera@example.com')->assertOk();
        $link = $this->link();

        $res = $this->actingAs($this->sam)->get('/account')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Account/Settings')
            ->where('account.pending_email', 'sam.rivera@example.com')
            ->where('account.email', 'sam@example.com')
            ->where('can', ['updateProfile' => true, 'resendEmailChange' => true, 'cancelEmailChange' => true]));

        $html = $res->getContent();
        $this->assertStringNotContainsString('email/verify', $html);
        $this->assertStringNotContainsString(parse_url($link, PHP_URL_QUERY), $html);
        $this->assertStringNotContainsString(EmailChange::hashFor($this->sam->fresh()), $html);
    }
}
