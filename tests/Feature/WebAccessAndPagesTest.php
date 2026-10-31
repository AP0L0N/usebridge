<?php

namespace Tests\Feature;

use Database\Seeders\BridgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WebAccessAndPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BridgeSeeder::class);
    }

    #[Test]
    public function guests_see_the_landing_page_and_every_app_route_sends_them_to_login(): void
    {
        $this->get('/')->assertOk()->assertViewIs('landing');
        foreach (['/portfolio', '/projects/blue-harbor', '/projects/blue-harbor/tickets/BH-1', '/usage', '/polymarket', '/account', '/account/tokens', '/welcome'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
        $this->post('/projects/blue-harbor/tickets', ['title' => 'x'])->assertRedirect('/login');
        $this->withHeader('X-Inertia', 'true')->get('/usage')->assertStatus(409)->assertHeader('X-Inertia-Location', route('login'));
    }

    #[Test]
    public function the_shared_workspace_password_is_gone(): void
    {
        $this->get('/gate')->assertNotFound();
        $this->post('/gate', ['password' => 'anything'])->assertNotFound();
        $this->post('/gate/lock')->assertNotFound();
        // A session that still carries the old unlock flag gets nothing.
        $this->withSession(['bridge_gate_unlocked' => true])->get('/usage')->assertRedirect('/login');
        $this->withSession(['bridge_gate_unlocked' => true])->get('/')->assertViewIs('landing');
        $this->assertNull(config('bridge.gate_password'));
        $this->assertNull(config('bridge.gate_enabled'));
    }

    #[Test]
    public function the_owner_signs_in_normally_and_reaches_portfolio_board_and_polymarket(): void
    {
        $owner = $this->workspaceOwner();
        $this->assertSame('owner@example.test', $owner->email); // BRIDGE_OWNER_EMAIL in phpunit.xml
        $owner->forceFill(['password' => Hash::make('owner-throwaway-pass-1'), 'email_verified_at' => now()])->save();

        $this->postJson('/login', ['email' => 'owner@example.test', 'password' => 'owner-throwaway-pass-1'])
            ->assertOk()->assertJsonPath('redirect', route('portfolio'));
        $this->assertAuthenticatedAs($owner, 'web');

        $this->get(route('portfolio'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Portfolio')->has('projects', 2)->where('features.polymarket', true));
        $this->get('/projects/blue-harbor')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Board'));
        $this->get('/polymarket')->assertOk();
    }

    #[Test]
    public function a_signed_in_member_of_the_owner_workspace_gets_404_on_polymarket(): void
    {
        $member = $this->workspaceUser(['password' => Hash::make('member-throwaway-pass-1'), 'email_verified_at' => now()]);
        $this->postJson('/login', ['email' => $member->email, 'password' => 'member-throwaway-pass-1'])->assertOk();

        $this->get('/polymarket')->assertNotFound();
        $this->get('/usage')->assertOk()->assertInertia(fn (Assert $page) => $page->where('features.polymarket', false));
    }

    #[Test]
    public function project_view_renders_and_old_screens_redirect(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00'); // seeded update falls inside the 7-day timeline
        $this->asOwner();

        $this->get(route('projects.show', 'blue-harbor'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Board')
                ->where('board.project.slug', 'blue-harbor')
                ->has('board.columns', 6)
                ->where('ticket', null)
                ->missing('openInputs')
                ->missing('statusUpdates'));

        foreach (['/ops', '/agents', '/queue'] as $old) {
            $this->get($old)->assertRedirect('/');
        }
        Carbon::setTestNow();
    }

    #[Test]
    public function status_update_can_be_created(): void
    {
        $this->asOwner();

        $this->post(route('projects.status-updates.store', 'northwind'), [
            'body' => 'Checkpoint note from test.',
            'agent_id' => 'agent-anatoly',
        ])->assertRedirect();

        $this->assertDatabaseHas('status_updates', [
            'project_id' => 'proj-northwind',
            'agent_id' => 'agent-anatoly',
            'body' => 'Checkpoint note from test.',
        ]);
    }
}
