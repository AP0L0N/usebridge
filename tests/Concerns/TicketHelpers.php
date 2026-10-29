<?php

namespace Tests\Concerns;

use App\Models\User;
use Database\Seeders\BridgeSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/** Shared setup for the tickets tests: seeded agents/projects, a fixed clock, API + gate helpers. */
trait TicketHelpers
{
    protected const JANICE = BridgeSeeder::AGENT_JANICE; // PM of Concierge Avant (CA)

    protected const ANATOLY = BridgeSeeder::AGENT_ANATOLY; // PM of Merkwell (ME)

    protected const CAPTAIN = BridgeSeeder::AGENT_WILLIAM;

    protected const CA = BridgeSeeder::PROJ_CONCIERGE;

    protected const MW = BridgeSeeder::PROJ_MERKWELL;

    protected function bootTickets(string $now = '2026-10-29 10:00:00'): void
    {
        Carbon::setTestNow($now);
        $this->seed(BridgeSeeder::class);
    }

    protected function api(): static
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        return $this;
    }

    protected function gate(): static
    {
        return $this->withSession(['bridge_gate_unlocked' => true]);
    }

    protected function newTicket(array $data = [], string $project = self::CA): array
    {
        return $this->postJson("/api/projects/$project/tickets", ['title' => 'Draft the brief', 'actor' => self::JANICE, ...$data])
            ->assertCreated()->json('data');
    }

    protected function waitingTicket(array $data = []): array
    {
        return $this->newTicket(['status' => 'waiting_tilen', 'ask' => 'Approve the copy?', 'ask_options' => ['Yes', 'No'], ...$data]);
    }

    protected function tilenSays(string $key, array $data, string $slug = 'concierge-avant'): TestResponse
    {
        return $this->gate()->postJson("/projects/$slug/tickets/$key/comments", $data);
    }

    protected function travelMinutes(int $m): void
    {
        Carbon::setTestNow(Carbon::now()->addMinutes($m));
    }
}
