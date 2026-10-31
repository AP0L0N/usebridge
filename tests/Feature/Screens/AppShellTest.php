<?php

namespace Tests\Feature\Screens;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\AccountService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The app shell (Shell/AppTopBar) renders from the shared Inertia props: nav = Portfolio + Usage, plus Polymarket
 * (workspace feature) and Admin (is_admin); the Owner chip is is_admin. Owner and admin are one flag today; the
 * Polymarket feature is per workspace, so a non-admin member of a Polymarket workspace is the only mixed case.
 */
class AppShellTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $sam;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->owner = app(CurrentWorkspace::class)->default()->owner;
        $this->sam = app(AccountService::class)->register('Sam Rivera', 'sam@example.test', 'garden-planner-27');
    }

    /** @return array<string, array{string}> */
    public static function appPages(): array
    {
        return ['portfolio' => ['/'], 'usage' => ['/usage'], 'account' => ['/account'], 'tokens' => ['/account/tokens']];
    }

    #[Test]
    #[DataProvider('appPages')]
    public function a_regular_user_sees_portfolio_and_usage_only(string $url): void
    {
        $this->actingAs($this->sam)->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('auth.user.initials', 'SR')
            ->where('auth.user.is_admin', false)
            ->where('features.polymarket', false)
            ->where('features.admin', false));
    }

    #[Test]
    #[DataProvider('appPages')]
    public function the_owner_sees_polymarket_admin_and_the_owner_flag(string $url): void
    {
        $this->actingAs($this->owner)->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('auth.user.is_admin', true)
            ->where('features.polymarket', true)
            ->where('features.admin', true));
    }

    #[Test]
    public function polymarket_follows_the_workspace_feature_not_the_owner_flag(): void
    {
        $ws = Workspace::query()->where('owner_user_id', $this->sam->id)->firstOrFail();
        $ws->forceFill(['features' => [...($ws->features ?? []), Workspace::FEATURE_POLYMARKET => true]])->save();

        $this->actingAs($this->sam)->get('/')->assertInertia(fn (Assert $p) => $p
            ->where('auth.user.is_admin', false)
            ->where('features.polymarket', true)
            ->where('features.admin', false));
        $this->get('/admin/users')->assertNotFound();
    }

    #[Test]
    public function gated_routes_still_answer_404_for_a_regular_user(): void
    {
        $this->actingAs($this->sam);
        $this->get('/polymarket')->assertNotFound();
        $this->get('/admin/users')->assertNotFound();
    }

    #[Test]
    public function the_admin_page_renders_with_the_owner_shell(): void
    {
        $this->actingAs($this->owner)->get('/admin/users')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Admin/Users')->where('features.admin', true)->where('auth.user.is_admin', true));
    }

    #[Test]
    public function guest_pages_carry_no_account_or_nav_entries(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('auth.user', null)->where('features.admin', false));
    }

    #[Test]
    public function the_app_root_view_links_the_favicons(): void
    {
        $html = $this->actingAs($this->sam)->get('/')->getContent();

        $this->assertStringContainsString('<link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">', $html);
        $this->assertStringContainsString('<link rel="icon" href="/brand/favicon-32.png" sizes="32x32" type="image/png">', $html);
        $this->assertStringContainsString('<link rel="icon" href="/brand/favicon-16.png" sizes="16x16" type="image/png">', $html);
        $this->assertStringContainsString('<link rel="apple-touch-icon" href="/brand/favicon-180.png">', $html);
        foreach (['favicon.svg', 'favicon-16.png', 'favicon-32.png', 'favicon-180.png'] as $f) {
            $this->assertFileExists(public_path("brand/$f"));
        }
    }
}
