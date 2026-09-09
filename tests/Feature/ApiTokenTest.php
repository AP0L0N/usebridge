<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\BridgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BridgeSeeder::class);
    }

    #[Test]
    public function api_requires_bearer_token(): void
    {
        $this->getJson('/api/projects')->assertUnauthorized();
    }

    #[Test]
    public function sanctum_token_can_list_and_create_status_update(): void
    {
        $user = User::factory()->create([
            'email' => 'captain@lantis.internal',
            'name' => 'Captain William Diego',
        ]);
        Sanctum::actingAs($user, ['ceo']);

        $this->getJson('/api/projects')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->postJson('/api/status-updates', [
            'project_id' => 'proj-merkwell',
            'agent_id' => 'agent-anatoly',
            'body' => 'API status note from Captain.',
        ])->assertCreated()
            ->assertJsonPath('data.body', 'API status note from Captain.');

        $this->assertDatabaseHas('status_updates', [
            'project_id' => 'proj-merkwell',
            'body' => 'API status note from Captain.',
        ]);
    }
}
