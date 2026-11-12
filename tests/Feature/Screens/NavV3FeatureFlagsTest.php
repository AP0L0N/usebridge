<?php

namespace Tests\Feature\Screens;

use App\Models\User;
use App\Services\Auth\AccountService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Nav v3 (portfolio-workstreams README §6): Polymarket and Power Saver are hidden behind BRIDGE_FEATURE_POLYMARKET and
 * BRIDGE_FEATURE_POWER_SAVER, default off. Off = no nav flag and the web routes redirect to /portfolio; on = as before.
 */
class NavV3FeatureFlagsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->owner = app(CurrentWorkspace::class)->default()->owner;
    }

    #[Test]
    public function both_features_are_off_by_default(): void
    {
        $this->assertFalse(config('bridge.features.polymarket'));
        $this->assertFalse(config('bridge.features.power_saver'));
    }

    #[Test]
    public function hidden_features_are_left_out_of_the_nav_even_for_the_owner(): void
    {
        $this->actingAs($this->owner)->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('features.polymarket', false)
            ->where('features.power_saver', false)
            ->where('features.admin', true));
    }

    #[Test]
    public function hidden_routes_redirect_to_the_portfolio(): void
    {
        $this->actingAs($this->owner);
        foreach (['/polymarket', '/polymarket/anything', '/power-saver', '/power-saver/x/y'] as $uri) {
            $this->get($uri)->assertStatus(302)->assertRedirect('/portfolio');
        }
        $this->patchJson('/power-saver', ['thresholds' => []])->assertStatus(302)->assertRedirect('/portfolio');
        $this->get('/portfolio')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Portfolio'));
    }

    #[Test]
    public function guests_still_go_to_login_first(): void
    {
        $this->get('/power-saver')->assertRedirect('/login');
        $this->get('/polymarket')->assertRedirect('/login');
    }

    #[Test]
    public function switched_on_the_nav_items_and_routes_come_back(): void
    {
        config(['bridge.features.polymarket' => true, 'bridge.features.power_saver' => true]);

        $this->actingAs($this->owner)->get('/')->assertInertia(fn (Assert $p) => $p
            ->where('features.polymarket', true)
            ->where('features.power_saver', true));
        $this->get('/power-saver')->assertOk()->assertInertia(fn (Assert $p) => $p->component('PowerSaver'));
        $this->get('/polymarket')->assertOk();
        $this->get('/polymarket/anything')->assertNotFound();
    }

    #[Test]
    public function switched_on_polymarket_still_needs_the_owners_workspace_feature(): void
    {
        config(['bridge.features.polymarket' => true]);
        $sam = app(AccountService::class)->register('Sam Rivera', 'sam@example.test', 'garden-planner-27');

        $this->actingAs($sam)->get('/')->assertInertia(fn (Assert $p) => $p->where('features.polymarket', false));
        $this->get('/polymarket')->assertNotFound();
    }
}
