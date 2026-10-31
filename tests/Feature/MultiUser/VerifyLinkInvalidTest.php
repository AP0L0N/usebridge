<?php

namespace Tests\Feature\MultiUser;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** S5: an invalid or expired verification link gets a friendly page with a resend button, not a raw 403. */
class VerifyLinkInvalidTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'This verification link is invalid or has expired. Links work for 60 minutes; send yourself a new one below.';

    private function link(User $user, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), ['id' => $user->id, 'hash' => sha1($user->email)]);
    }

    #[Test]
    public function a_logged_in_unverified_user_gets_the_verify_page_with_the_message_and_a_working_resend(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => 'sam@example.test']);
        $expired = $this->link($user);
        $this->travel(61)->minutes();

        foreach ([$expired, $this->link($user).'x', str_replace('/'.sha1($user->email), '/'.sha1('other@example.test'), $this->link($user))] as $bad) {
            $this->actingAs($user)->get($bad)->assertOk()->assertInertia(fn (Assert $p) => $p
                ->component('Auth/VerifyEmail')->where('linkError', self::MESSAGE)
                ->where('resendUrl', '/email/verification-notification')->where('email', 'sam@example.test'));
        }
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->postJson('/email/verification-notification')->assertOk();

        // The fresh link still works.
        $this->get($this->link($user))->assertRedirect(route('account.tokens'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        // Verified already: a stale link just goes to the boards; the plain notice has no error.
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh())->get($expired)->assertRedirect(route('portfolio'));
    }

    #[Test]
    public function a_guest_goes_to_login_with_the_message_then_lands_on_the_verify_page(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'sam@example.test', 'password' => 'quiet-meadow-lantern-42']);
        $bad = $this->link($user).'x';

        $this->get($bad)->assertRedirect(route('login'))
            ->assertSessionHas('error', 'This verification link is invalid or has expired. Log in and send yourself a new one.');
        $this->getJson($bad)->assertForbidden()->assertJsonPath('expired', true);
        $this->assertStringNotContainsString('Invalid signature', $this->get($bad)->getContent());

        $this->actingAs($user)->get('/email/verify')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Auth/VerifyEmail')->where('linkError', null));
    }
}
