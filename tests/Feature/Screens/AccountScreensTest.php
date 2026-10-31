<?php

namespace Tests\Feature\Screens;

use App\Models\User;
use App\Services\Auth\AccountService;
use App\Services\Auth\ApiTokens;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/** Account settings and API tokens screens: own account only, tokens masked, plaintext only in the POST response. */
class AccountScreensTest extends TestCase
{
    use RefreshDatabase;
    use TicketHelpers;

    private User $sam;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->sam = app(AccountService::class)->register('Sam Rivera', 'sam@example.test', 'garden-planner-27')->fresh();
    }

    #[Test]
    public function guests_are_sent_to_login(): void
    {
        $this->get('/account')->assertRedirect('/login');
        $this->get('/account/tokens')->assertRedirect('/login');
        // a session with only the old (removed) gate flag is not an account
        $this->withSession(['bridge_gate_unlocked' => true])->get('/account')->assertRedirect('/login');
    }

    #[Test]
    public function the_account_page_has_the_own_account_sessions_and_deletion_counts(): void
    {
        // Tilen's workspace has projects / tickets; none of them may be counted for Sam.
        $this->bootTickets();
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $this->sam->id, 'ip_address' => '10.0.0.9',
            'user_agent' => 'Mozilla/5.0 (Macintosh) Firefox/140.0', 'payload' => '', 'last_activity' => now()->getTimestamp()]);

        $this->actingAs($this->sam)->get('/account')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Account/Settings')
            ->where('account', [
                'id' => $this->sam->id, 'name' => 'Sam Rivera', 'email' => 'sam@example.test', 'email_verified' => false,
                'pending_email' => null, 'timezone' => 'Europe/Ljubljana', 'is_admin' => false, 'delete_requested_at' => null,
            ])
            ->has('sessions', 1, fn (Assert $s) => $s
                ->where('id', hash('sha256', 'other-device'))->where('ip', '10.0.0.9')->where('current', false)
                ->hasAll(['user_agent', 'last_active']))
            ->where('deletion.graceDays', 7)
            ->where('deletion.counts', ['projects' => 0, 'tickets' => 0, 'tokens' => 0])
            ->where('minPassword', AccountService::MIN_PASSWORD)
            ->where('can', ['updateProfile' => true, 'resendEmailChange' => true, 'cancelEmailChange' => true])
            ->has('timezones'));

        // JSON clients keep the JSON answer.
        $this->getJson('/account')->assertOk()->assertJsonPath('user.email', 'sam@example.test');
    }

    #[Test]
    public function the_tilen_account_page_counts_his_own_workspace(): void
    {
        $this->bootTickets();
        $tilen = app(CurrentWorkspace::class)->default()->owner;

        $this->actingAs($tilen)->get('/account')->assertInertia(fn (Assert $p) => $p
            ->where('account.is_admin', true)
            ->where('deletion.counts.projects', fn ($n) => $n > 0));
    }

    #[Test]
    public function the_tokens_page_lists_masked_tokens_and_the_mcp_url(): void
    {
        config(['app.url' => 'https://bridge.example.test/']);
        $this->sam->markEmailAsVerified();
        $new = app(ApiTokens::class)->create($this->sam, $this->sam->ownedWorkspaces()->first(), 'Grok bot (Ava)', 'write', 90);

        $res = $this->actingAs($this->sam)->get('/account/tokens')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Account/Tokens')
            ->where('mcpUrl', 'https://bridge.example.test/mcp')
            ->where('emailVerified', true)
            ->where('expiryDays', ApiTokens::EXPIRY_DAYS)
            ->has('tokens', 1, fn (Assert $t) => $t
                ->where('name', 'Grok bot (Ava)')
                ->where('scope', 'write')
                ->where('suffix', substr($new['plain_text'], -4))
                ->where('masked', 'brg_live_••••'.substr($new['plain_text'], -4))
                ->etc()));

        $this->assertStringNotContainsString($new['plain_text'], $res->getContent());
        $this->assertStringNotContainsString(hash('sha256', $new['plain_text']), $res->getContent());
        $this->assertSame([], array_values(array_diff(array_keys($res->inertiaProps('tokens.0')), [
            'id', 'name', 'suffix', 'masked', 'legacy', 'scope', 'abilities', 'created_at', 'last_used_at', 'expires_at', 'agent_id',
        ])));
    }

    #[Test]
    public function the_plaintext_is_only_in_the_creation_response(): void
    {
        $this->sam->markEmailAsVerified();

        $plain = $this->actingAs($this->sam)->postJson('/account/tokens', ['name' => 'bot', 'scope' => 'read', 'expires' => 30])
            ->assertCreated()->json('plain_text_token');
        $this->assertStringStartsWith('brg_live_', $plain);

        $this->assertStringNotContainsString($plain, $this->get('/account/tokens')->getContent());
        $this->assertStringNotContainsString($plain, $this->getJson('/account/tokens')->getContent());
        $this->assertStringNotContainsString($plain, $this->get('/account')->getContent());
        $this->assertStringNotContainsString($plain, $this->get('/')->getContent());
    }

    #[Test]
    public function an_unverified_user_sees_the_page_but_cannot_create(): void
    {
        $this->actingAs($this->sam)->get('/account/tokens')->assertInertia(fn (Assert $p) => $p->where('emailVerified', false)->where('tokens', []));
        $this->postJson('/account/tokens', ['name' => 'bot', 'scope' => 'read'])->assertForbidden()->assertJsonPath('verified', false);
    }

    #[Test]
    public function the_tokens_page_shows_only_own_tokens(): void
    {
        $tilen = app(CurrentWorkspace::class)->default()->owner;
        $tilen->createToken('captain', ['ceo']);

        $this->actingAs($this->sam)->get('/account/tokens')->assertInertia(fn (Assert $p) => $p->where('tokens', []));
    }
}
