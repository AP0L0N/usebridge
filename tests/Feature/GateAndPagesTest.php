<?php

namespace Tests\Feature;

use App\Models\CeoQueueItem;
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
    public function cockpit_ops_agents_queue_render_when_unlocked(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);

        $this->get(route('projects.show', 'merkwell'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Cockpit'));

        $this->get(route('ops'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ops')
                ->where('leadOrbitConnected', false)
                ->has('items', 0));

        $this->get(route('agents'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Agents')->has('rows', 5));

        $this->get(route('queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Queue')->has('items', 4));
    }

    #[Test]
    public function queue_dismiss_and_assign_persist(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);

        $open = CeoQueueItem::query()->where('status', 'open')->firstOrFail();

        $this->post(route('queue.assign', $open->id), [
            'agent_id' => 'agent-anatoly',
        ])->assertRedirect();

        $open->refresh();
        $this->assertSame('assigned', $open->status);
        $this->assertSame('agent-anatoly', $open->assigned_agent_id);

        $this->post(route('queue.dismiss', $open->id))->assertRedirect();
        $open->refresh();
        $this->assertSame('dismissed', $open->status);
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
