<?php

namespace Tests\Feature\MultiUser;

use App\Models\User;
use App\Services\Auth\AccountService;
use App\Services\Auth\ApiTokens;
use App\Support\Workspaces\CurrentWorkspace;
use Database\Seeders\BridgeSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Email verification and the account's own API tokens (scope, expiry, brg_live_ prefix, masked list, revoke). */
class ApiTokensTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        $this->seed(BridgeSeeder::class);
    }

    private function marta(bool $verified = true): User
    {
        Notification::fake();
        $user = app(AccountService::class)->register('Marta', 'marta@example.test', self::PASSWORD);
        if ($verified) {
            $user->markEmailAsVerified();
        }

        return $user->fresh();
    }

    private function bearer(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, $uri, $data, ['Authorization' => "Bearer $token"]);
    }

    #[Test]
    public function a_verified_user_creates_a_prefixed_read_token_bound_to_their_workspace(): void
    {
        $marta = $this->marta();

        $res = $this->actingAs($marta)->postJson('/account/tokens', ['name' => 'grok', 'scope' => 'read', 'expires' => 30])->assertCreated();

        $plain = $res->json('plain_text_token');
        $this->assertStringStartsWith('brg_live_', $plain);
        $this->assertStringNotContainsString('|', $plain);
        $res->assertJsonPath('data.suffix', substr($plain, -4))
            ->assertJsonPath('data.masked', 'brg_live_••••'.substr($plain, -4))
            ->assertJsonPath('data.scope', 'read')
            ->assertJsonPath('data.abilities', ['boards:read'])
            ->assertJsonPath('data.expires_at', Carbon::now()->addDays(30)->toIso8601String());
        $row = DB::table('personal_access_tokens')->where('id', $res->json('data.id'))->first();
        $this->assertSame($marta->ownedWorkspaces()->value('id'), (int) $row->workspace_id);
        $this->assertSame(hash('sha256', $plain), $row->token);

        $this->bearer($plain, 'GET', '/api/projects')->assertOk()->assertJsonCount(0, 'data');
        $this->bearer($plain, 'POST', '/api/projects', ['name' => 'X'])->assertForbidden();

        $this->travel(31)->days();
        $this->bearer($plain, 'GET', '/api/projects')->assertUnauthorized();
    }

    #[Test]
    public function write_scope_includes_usage_and_polymarket_only_with_the_workspace_feature(): void
    {
        $base = ['boards:read', 'boards:write', 'status:write', 'agents:write'];
        $tilens = app(CurrentWorkspace::class)->default();
        $marta = $this->marta();

        $this->assertSame($base, ApiTokens::abilitiesFor('write', $marta->ownedWorkspaces()->first()));
        $this->assertSame([...$base, 'usage:write', 'polymarket:write'], ApiTokens::abilitiesFor('write', $tilens, $tilens->owner));
        // polymarket:write is for the workspace owner only (a member or no user gets usage:write but not polymarket).
        $this->assertSame([...$base, 'usage:write'], ApiTokens::abilitiesFor('write', $tilens, $this->workspaceUser()));
        $this->assertSame([...$base, 'usage:write'], ApiTokens::abilitiesFor('write', $tilens));
        $this->assertSame(['boards:read'], ApiTokens::abilitiesFor('read', $tilens));

        $this->actingAs($marta)->postJson('/account/tokens', ['name' => 'bot', 'scope' => 'write', 'expires' => null])->assertCreated()
            ->assertJsonPath('data.scope', 'write')->assertJsonPath('data.abilities', $base)->assertJsonPath('data.expires_at', null);
    }

    #[Test]
    public function creating_tokens_needs_a_verified_email_and_valid_input(): void
    {
        $marta = $this->marta(verified: false);

        $this->actingAs($marta)->postJson('/account/tokens', ['name' => 'grok', 'scope' => 'read'])
            ->assertForbidden()->assertJsonPath('verified', false);
        $this->assertSame(0, $marta->tokens()->count());
        try {
            app(ApiTokens::class)->create($marta, $marta->ownedWorkspaces()->first(), 'grok', 'read', null);
            $this->fail('unverified user created a token');
        } catch (AuthorizationException) {
        }

        $marta->markEmailAsVerified();
        $this->actingAs($marta)->postJson('/account/tokens', ['name' => 'grok', 'scope' => 'ceo'])->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->actingAs($marta)->postJson('/account/tokens', ['name' => 'grok', 'scope' => 'read', 'expires' => 7])->assertStatus(422)->assertJsonValidationErrors('expires');
        $this->assertSame(0, $marta->tokens()->count());
    }

    #[Test]
    public function the_list_shows_own_tokens_masked_and_legacy_ones_as_legacy(): void
    {
        $marta = $this->marta();
        app(ApiTokens::class)->create($marta, $marta->ownedWorkspaces()->first(), 'new', 'read', 90);
        $marta->createToken('old-ceo', ['ceo']); // made outside the service: no suffix = legacy
        $this->workspaceUser()->createToken('someone-else', ['*']);

        $data = $this->actingAs($marta)->getJson('/account/tokens')->assertOk()->json('data');

        $this->assertSame(['old-ceo', 'new'], array_column($data, 'name'));
        $this->assertSame([true, 'full', null], [$data[0]['legacy'], $data[0]['scope'], $data[0]['masked']]);
        $this->assertSame([false, 'read'], [$data[1]['legacy'], $data[1]['scope']]);
        $this->assertStringContainsString('••••', $data[1]['masked']);
        $this->assertArrayNotHasKey('token', $data[1]);
    }

    #[Test]
    public function only_own_tokens_can_be_revoked(): void
    {
        $marta = $this->marta();
        $mine = app(ApiTokens::class)->create($marta, $marta->ownedWorkspaces()->first(), 'mine', 'read', null);
        $theirs = $this->workspaceUser()->createToken('theirs', ['*']);

        $this->actingAs($marta)->deleteJson('/account/tokens/'.$theirs->accessToken->id)->assertNotFound();
        $this->assertNotNull($theirs->accessToken->fresh());

        $this->actingAs($marta)->deleteJson('/account/tokens/'.$mine['token']->id)->assertOk();
        $this->assertNull($mine['token']->fresh());
        $this->bearer($mine['plain_text'], 'GET', '/api/projects')->assertUnauthorized();
    }

    #[Test]
    public function legacy_unprefixed_tokens_keep_working(): void
    {
        $captain = $this->workspaceUser();
        $id = DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => User::class, 'tokenable_id' => $captain->id, 'name' => 'ceo', 'abilities' => '["ceo"]',
            'token' => hash('sha256', 'legacyplaintexttoken'), 'workspace_id' => app(CurrentWorkspace::class)->default()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->bearer("$id|legacyplaintexttoken", 'GET', '/api/projects')->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    public function the_signed_link_verifies_the_email_and_resend_mails_a_new_one(): void
    {
        $marta = $this->marta(verified: false);
        Notification::assertSentTo($marta, VerifyEmail::class);

        $this->actingAs($marta)->postJson('/email/verification-notification')->assertOk();
        Notification::assertSentToTimes($marta, VerifyEmail::class, 2);

        $this->getJson("/email/verify/{$marta->id}/".sha1($marta->email))->assertForbidden(); // unsigned
        $bad = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $marta->id, 'hash' => sha1('other@example.test')]);
        $this->getJson($bad)->assertForbidden();
        $this->assertFalse($marta->fresh()->hasVerifiedEmail());

        $good = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $marta->id, 'hash' => sha1($marta->email)]);
        $this->getJson($good)->assertOk()->assertJsonPath('verified', true);
        $this->assertTrue($marta->fresh()->hasVerifiedEmail());
    }
}
