<?php

namespace Tests\Concerns;

use Database\Seeders\BridgeSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/** Shared setup for the tickets tests: seeded agents/projects, a fixed clock, API + login helpers. */
trait TicketHelpers
{
    use RosterFile;

    protected const JANICE = BridgeSeeder::AGENT_JANICE; // PM of Blue Harbor (BH)

    protected const ANATOLY = BridgeSeeder::AGENT_ANATOLY; // PM of Northwind (NW)

    protected const CAPTAIN = BridgeSeeder::AGENT_WILLIAM;

    protected const BH = BridgeSeeder::PROJ_BLUE_HARBOR;

    protected const NW = BridgeSeeder::PROJ_NORTHWIND;

    protected function bootTickets(string $now = '2026-10-29 10:00:00'): void
    {
        Carbon::setTestNow($now);
        // Fixed prefixes, like an operator's roster file (BRIDGE_ROSTER_PATH): NW is not what "Northwind" generates.
        $this->useRoster(['ticket_prefixes' => [self::NW => 'NW', self::BH => 'BH']]);
        $this->seed(BridgeSeeder::class);
    }

    protected function api(): static
    {
        Sanctum::actingAs($this->workspaceUser(), ['*']);

        return $this;
    }

    protected function newTicket(array $data = [], string $project = self::BH): array
    {
        return $this->postJson("/api/projects/$project/tickets", ['title' => 'Draft the brief', 'actor' => self::JANICE, ...$data])
            ->assertCreated()->json('data');
    }

    protected function waitingTicket(array $data = []): array
    {
        return $this->newTicket(['status' => 'waiting_tilen', 'ask' => 'Approve the copy?', 'ask_options' => ['Yes', 'No'], ...$data]);
    }

    protected function tilenSays(string $key, array $data, string $slug = 'blue-harbor'): TestResponse
    {
        return $this->asOwner()->postJson("/projects/$slug/tickets/$key/comments", $data);
    }

    protected function travelMinutes(int $m): void
    {
        Carbon::setTestNow(Carbon::now()->addMinutes($m));
    }
}
