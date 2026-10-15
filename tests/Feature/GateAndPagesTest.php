<?php

namespace Tests\Feature;

use Database\Seeders\BridgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GateAndPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BridgeSeeder::class);
    }

    #[Test]
    public function guests_are_sent_to_gate(): void
    {
        $this->get('/')->assertRedirect(route('gate.show'));
    }

    #[Test]
    public function wrong_password_rejected(): void
    {
        $this->from(route('gate.show'))
            ->post(route('gate.unlock'), ['password' => 'nope'])
            ->assertRedirect(route('gate.show'))
            ->assertSessionHasErrors('password');
    }

    #[Test]
    public function correct_password_unlocks_portfolio(): void
    {
        $this->post(route('gate.unlock'), [
            'password' => config('bridge.gate_password'),
        ])->assertRedirect(route('portfolio'));

        $this->get(route('portfolio'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portfolio')
                ->has('projects', 2));
    }

    #[Test]
    public function project_view_renders_and_old_screens_redirect(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-09-10 12:00:00'); // seeded update falls inside the 7-day timeline
        $this->withSession(['bridge_gate_unlocked' => true]);

        $this->get(route('projects.show', 'concierge-avant'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Project')
                ->where('project.status', 'waiting')
                ->has('openInputs', 1)
                ->has('statusUpdates', 1));

        foreach (['/ops', '/agents', '/queue'] as $old) {
            $this->get($old)->assertRedirect('/');
        }
        \Illuminate\Support\Carbon::setTestNow();
    }

    #[Test]
    public function status_update_can_be_created(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);

        $this->post(route('projects.status-updates.store', 'merkwell'), [
            'body' => 'Checkpoint note from test.',
            'agent_id' => 'agent-anatoly',
        ])->assertRedirect();

        $this->assertDatabaseHas('status_updates', [
            'project_id' => 'proj-merkwell',
            'agent_id' => 'agent-anatoly',
            'body' => 'Checkpoint note from test.',
        ]);
    }
}
