<?php

namespace Tests\Feature\MultiUser;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\AccountService;
use Database\Seeders\BridgeSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountServiceTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    private function accounts(): AccountService
    {
        return app(AccountService::class);
    }

    #[Test]
    public function register_creates_the_user_and_their_own_workspace_as_owner(): void
    {
        $user = $this->accounts()->register('Marta', ' Marta@Example.test ', self::PASSWORD, 'Marta Labs');
        $again = $this->accounts()->register('Other', 'other@example.test', self::PASSWORD, 'Marta Labs');

        $this->assertSame('marta@example.test', $user->email);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertFalse($user->fresh()->is_admin);
        $ws = Workspace::query()->where('owner_user_id', $user->id)->firstOrFail();
        $this->assertSame(['marta-labs', 'Marta Labs', false], [$ws->slug, $ws->name, $ws->hasFeature('polymarket')]);
        $this->assertSame('owner', $ws->users()->find($user->id)->pivot->role);
        $this->assertSame('marta-labs-2', Workspace::query()->where('owner_user_id', $again->id)->value('slug'));
    }

    #[Test]
    public function register_rejects_taken_emails_and_short_passwords(): void
    {
        $this->accounts()->register('Marta', 'marta@example.test', self::PASSWORD);

        foreach ([['marta@example.test', self::PASSWORD, 'email'], ['new@example.test', 'short', 'password'], ['not-an-email', self::PASSWORD, 'email']] as [$email, $pw, $field]) {
            try {
                $this->accounts()->register('X', $email, $pw);
                $this->fail("expected a validation error on $field");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
        $this->assertSame(1, Workspace::query()->where('slug', '!=', config('bridge.default_workspace'))->count());
    }

    #[Test]
    public function a_new_users_token_acts_in_their_workspace_only(): void
    {
        $this->seed(BridgeSeeder::class);
        $token = $this->accounts()->register('Marta', 'marta@example.test', self::PASSWORD)->createToken('grok', ['boards:read'])->plainTextToken;

        $this->getJson('/api/projects', ['Authorization' => "Bearer $token"])->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function password_reset_mails_a_link_and_the_token_sets_the_new_password(): void
    {
        Notification::fake();
        $user = $this->accounts()->register('Marta', 'marta@example.test', self::PASSWORD);

        $this->assertSame(Password::RESET_LINK_SENT, $this->accounts()->sendPasswordResetLink('MARTA@example.test'));
        $this->assertSame(Password::INVALID_USER, $this->accounts()->sendPasswordResetLink('nobody@example.test'));
        $this->assertSame(Password::RESET_THROTTLED, $this->accounts()->sendPasswordResetLink('marta@example.test'));

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user, &$token) {
            $token = $n->token;
            $url = $n->toMail($user)->actionUrl;

            return str_ends_with($url, "/reset-password/$token?email=marta%40example.test");
        });

        $this->assertSame(Password::INVALID_TOKEN, $this->accounts()->resetPassword('marta@example.test', 'wrong', 'a brand new passphrase'));
        $this->assertSame(Password::PASSWORD_RESET, $this->accounts()->resetPassword('marta@example.test', $token, 'a brand new passphrase'));
        $this->assertTrue(Hash::check('a brand new passphrase', $user->fresh()->password));
        // Tokens are single use.
        $this->assertSame(Password::INVALID_TOKEN, $this->accounts()->resetPassword('marta@example.test', $token, 'yet another passphrase'));
    }

    #[Test]
    public function a_password_reset_revokes_all_the_users_api_tokens(): void
    {
        Notification::fake();
        $user = $this->accounts()->register('Marta', 'marta@example.test', self::PASSWORD);
        $other = $this->accounts()->register('Kim', 'kim@example.test', self::PASSWORD);
        $plain = $user->createToken('laptop', ['*'])->plainTextToken;
        $user->createToken('mcp', ['boards:read']);
        $other->createToken('kim-bot', ['*']);
        $this->getJson('/api/projects', ['Authorization' => "Bearer $plain"])->assertOk();

        $this->accounts()->sendPasswordResetLink('marta@example.test');
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });
        // A failed reset changes nothing.
        $this->assertSame(Password::INVALID_TOKEN, $this->accounts()->resetPassword('marta@example.test', 'wrong', 'a brand new passphrase'));
        $this->assertSame(2, $user->tokens()->count());

        $this->assertSame(Password::PASSWORD_RESET, $this->accounts()->resetPassword('marta@example.test', $token, 'a brand new passphrase'));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/projects', ['Authorization' => "Bearer $plain"])->assertUnauthorized();
    }

    #[Test]
    public function change_password_needs_the_current_one(): void
    {
        $user = $this->accounts()->register('Marta', 'marta@example.test', self::PASSWORD);

        try {
            $this->accounts()->changePassword($user, 'wrong', 'a brand new passphrase');
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('current_password', $e->errors());
        }
        $this->accounts()->changePassword($user, self::PASSWORD, 'a brand new passphrase');
        $this->assertTrue(Hash::check('a brand new passphrase', User::query()->find($user->id)->password));
    }
}
