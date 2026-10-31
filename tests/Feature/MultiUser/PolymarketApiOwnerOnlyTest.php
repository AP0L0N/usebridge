<?php

namespace Tests\Feature\MultiUser;

use App\Models\PolymarketSnapshot;
use App\Models\User;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\PolymarketFeedTest;
use Tests\TestCase;

/**
 * Taz pre-prod pass #2: the Polymarket API is owner-only. polymarket:write is only granted to the workspace owner's
 * tokens, and every api/polymarket/* route checks the token's user is the owner on each request (404 otherwise, like
 * the web desk), so a member token with a forged ability (or '*' / 'ceo') gets nothing.
 */
class PolymarketApiOwnerOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function feed(string $plain): TestResponse
    {
        return $this->call('POST', '/api/polymarket/feed', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$plain,
        ], PolymarketFeedTest::body());
    }

    /** A token bound to the owner's workspace, as ApiTokens / BridgeApiSeeder issue them. */
    private function token(User $user, array $abilities): string
    {
        $new = $user->createToken('t', $abilities);
        $new->accessToken->forceFill(['workspace_id' => app(CurrentWorkspace::class)->default()->id])->save();

        return $new->plainTextToken;
    }

    #[Test]
    public function a_member_token_with_a_forged_polymarket_ability_is_rejected_and_writes_nothing(): void
    {
        $member = $this->workspaceUser(['email_verified_at' => now()]);
        foreach ([['polymarket:write'], ['*'], ['ceo'], ['boards:read', 'boards:write', 'polymarket:write']] as $abilities) {
            $this->feed($this->token($member, $abilities))->assertNotFound();
        }
        $this->assertSame(0, PolymarketSnapshot::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_owner_token_with_polymarket_write_posts_the_feed(): void
    {
        $this->feed($this->token($this->workspaceOwner(), ['polymarket:write']))->assertSuccessful();
        $this->assertSame(1, PolymarketSnapshot::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_owner_token_still_needs_the_ability(): void
    {
        $this->feed($this->token($this->workspaceOwner(), ['boards:read']))->assertForbidden();
    }

    #[Test]
    public function token_creation_grants_polymarket_write_to_the_owner_only(): void
    {
        $member = $this->workspaceUser(['email_verified_at' => now()]);
        $this->actingAs($member)->postJson('/account/tokens', ['name' => 'bot', 'scope' => 'write', 'expires' => null])
            ->assertCreated()
            ->assertJsonPath('data.abilities', fn (array $a) => ! in_array('polymarket:write', $a, true));

        $this->app['auth']->forgetGuards();
        $owner = $this->workspaceOwner();
        $owner->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($owner)->postJson('/account/tokens', ['name' => 'desk', 'scope' => 'write', 'expires' => null])
            ->assertCreated()->assertJsonPath('data.abilities', fn (array $a) => in_array('polymarket:write', $a, true));
    }

    #[Test]
    public function every_api_polymarket_route_carries_the_owner_check(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/polymarket'));
        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $this->assertContains('App\Http\Middleware\RequireWorkspaceFeature:polymarket,owner', $route->gatherMiddleware(), $route->uri());
        }
    }
}
