<?php

namespace Tests\Feature\MultiUser;

use App\Support\WorkspaceGate;
use Database\Seeders\BridgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AUTH-1 gate fails closed (CoS decision 31 Oct, docs/MULTI-USER-PLAN.md §2): no default password, unset/empty rejects
 * every attempt; BRIDGE_GATE_ENABLED=false retires the gate (404, unlocked sessions go to login); API tokens unaffected.
 */
class GateFailsClosedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BridgeSeeder::class);
    }

    /** Guesses an unconfigured gate must reject. BRIDGE_GATE_PROBE (optional, never committed) adds one more locally. */
    private function guesses(): array
    {
        $probe = (string) getenv('BRIDGE_GATE_PROBE');

        return [...['', ' ', 'test-gate-password', 'password', 'null', '0'], ...($probe === '' ? [] : [$probe])];
    }

    #[Test]
    public function the_config_has_no_default_password(): void
    {
        $config = file_get_contents(config_path('bridge.php'));
        $this->assertMatchesRegularExpression("/'gate_password' => \(string\) env\('BRIDGE_GATE_PASSWORD'\),/", $config);
        $this->assertStringContainsString('BRIDGE_GATE_PASSWORD=', file_get_contents(base_path('.env.example')));
        $this->assertMatchesRegularExpression('/^BRIDGE_GATE_PASSWORD=$/m', file_get_contents(base_path('.env.example')));
    }

    #[Test]
    public function an_unset_or_empty_password_rejects_every_attempt(): void
    {
        foreach ([null, ''] as $configured) {
            config(['bridge.gate_password' => $configured]);
            $this->assertFalse(WorkspaceGate::configured());

            foreach ($this->guesses() as $guess) {
                $this->assertFalse(WorkspaceGate::accepts($guess));
                $this->from('/gate')->post('/gate', ['password' => $guess])
                    ->assertRedirect('/gate')->assertSessionHasErrors('password')->assertSessionMissing(WorkspaceGate::SESSION_KEY);
                $this->get('/')->assertRedirect(route('login'));
            }
        }

        $this->get('/gate')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Gate')->where('configured', false));
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('gateAvailable', false));
        $this->from('/gate')->post('/gate', ['password' => 'anything'])
            ->assertSessionHasErrors(['password' => 'The workspace gate is not configured. Log in with your email.']);
    }

    #[Test]
    public function a_configured_password_unlocks_and_others_are_wrong(): void
    {
        config(['bridge.gate_password' => 'test-gate-password']);
        $this->get('/gate')->assertInertia(fn (Assert $p) => $p->where('configured', true));

        $this->from('/gate')->post('/gate', ['password' => 'test-gate-passwor'])
            ->assertSessionHasErrors(['password' => 'Wrong password.']);
        $this->post('/gate', ['password' => 'test-gate-password'])->assertRedirect(route('portfolio'));
        $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Portfolio')->where('gateUnlocked', true));
    }

    #[Test]
    public function a_retired_gate_404s_and_unlocked_sessions_go_to_login(): void
    {
        config(['bridge.gate_password' => 'test-gate-password', 'bridge.gate_enabled' => false]);

        $this->get('/gate')->assertNotFound();
        $this->post('/gate', ['password' => 'test-gate-password'])->assertNotFound()->assertSessionMissing(WorkspaceGate::SESSION_KEY);
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('gateAvailable', false));

        // A session unlocked before the gate was retired unlocks nothing.
        $this->withSession([WorkspaceGate::SESSION_KEY => true]);
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/projects/blue-harbor')->assertRedirect(route('login'));
        $this->get('/usage')->assertRedirect(route('login'));
        $this->get('/', ['X-Inertia' => 'true'])->assertStatus(409);
        $this->post('/gate/lock')->assertRedirect(route('login'));
    }

    #[Test]
    public function api_tokens_work_whatever_the_gate(): void
    {
        foreach ([['bridge.gate_password' => null], ['bridge.gate_enabled' => false]] as $gate) {
            config($gate);
            Sanctum::actingAs($this->workspaceUser(), ['*']);
            $this->getJson('/api/board-columns')->assertOk()->assertJsonCount(6, 'data');
            $this->getJson('/api/projects')->assertOk();
        }
    }
}
