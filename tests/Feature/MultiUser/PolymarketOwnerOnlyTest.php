<?php

namespace Tests\Feature\MultiUser;

use App\Models\PolymarketSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\PolymarketFeedTest;
use Tests\TestCase;

/**
 * Polymarket is a per-workspace feature: on for Tilen's workspace (backfill), hidden (404, no nav) everywhere else. On
 * the web only the workspace owner sees it: members and every other user get 404 and no nav item.
 */
class PolymarketOwnerOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function ingest(string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->call('POST', '/api/polymarket/feed', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], PolymarketFeedTest::body());
    }

    private function otherWorkspaceToken(array $features = []): string
    {
        $owner = User::factory()->create();
        Workspace::query()->create(['slug' => 'grok', 'name' => 'Grok', 'owner_user_id' => $owner->id, 'features' => $features]);

        return $owner->createToken('grok', ['*'])->plainTextToken;
    }

    #[Test]
    public function tilens_workspace_keeps_the_desk_the_nav_item_and_the_ingest(): void
    {
        $this->ingest($this->workspaceUser()->createToken('captain', ['ceo'])->plainTextToken)->assertCreated();

        $this->asOwner()->get('/polymarket')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Polymarket/Desk')->where('features.polymarket', true));
        $this->asOwner()->get('/usage')
            ->assertInertia(fn (Assert $p) => $p->where('features.polymarket', true));
    }

    #[Test]
    public function another_workspace_gets_404_on_the_ingest_and_writes_nothing(): void
    {
        $this->ingest($this->otherWorkspaceToken())->assertNotFound();
        $this->assertSame(0, PolymarketSnapshot::query()->count());
    }

    #[Test]
    public function with_the_feature_on_another_workspace_only_ever_sees_its_own_desk(): void
    {
        $this->ingest($this->otherWorkspaceToken(['polymarket' => true]))->assertCreated();

        $this->assertSame(0, PolymarketSnapshot::query()->where('workspace_id', app(CurrentWorkspace::class)->default()->id)->count());
        $this->asOwner()->get('/polymarket')
            ->assertInertia(fn (Assert $p) => $p->where('feed', null));
    }

    #[Test]
    public function switching_the_feature_off_hides_page_and_nav_for_the_owner(): void
    {
        $tilen = app(CurrentWorkspace::class)->default();
        $tilen->forceFill(['features' => ['polymarket' => false]])->save();

        $this->asOwner()->get('/polymarket')->assertNotFound();
        $this->asOwner()->get('/usage')
            ->assertInertia(fn (Assert $p) => $p->where('features.polymarket', false));
    }

    #[Test]
    public function guest_pages_share_no_features(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->component('Auth/Login')->where('features.polymarket', false));
    }

    #[Test]
    public function on_the_web_only_the_instance_owner_sees_the_desk(): void
    {
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame($owner->id, app(CurrentWorkspace::class)->default()->owner_user_id);
        $this->actingAs($owner)->get('/polymarket')->assertOk();
        $this->actingAs($owner)->get('/usage')->assertInertia(fn (Assert $p) => $p->where('features.polymarket', true));

        // A member of the owner's workspace, and a user with their own workspace: 404 and no nav item.
        $member = $this->workspaceUser(['email' => 'member@example.test']);
        $stranger = User::factory()->create(['email' => 'alex@example.test']);
        Workspace::query()->create(['slug' => 'alex', 'name' => 'Alex', 'owner_user_id' => $stranger->id, 'features' => []]);
        foreach ([$member, $stranger] as $user) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($user)->get('/polymarket')->assertNotFound();
            $this->actingAs($user)->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('features.polymarket', false));
        }

        // Guests never reach it.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get('/polymarket')->assertRedirect(route('login'));
    }
}
