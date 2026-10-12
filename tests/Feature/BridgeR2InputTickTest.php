<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\RequiredInput;
use App\Models\User;
use App\Services\ProjectStatus;
use Database\Seeders\BridgeSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BridgeR2InputTickTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/projects/merkwell/inputs/%s/resolve';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 21:00:00');
        $this->seed(BridgeSeeder::class);
        foreach ([['in-a', 80], ['in-b', 50], ['in-c', 5]] as [$id, $h]) {
            RequiredInput::create(['id' => $id, 'project_id' => 'proj-merkwell', 'text' => $id, 'waiting_on' => 'Denis', 'since' => now()->subHours($h)]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function derived(): array
    {
        return ProjectStatus::derive(Project::with('requiredInputs')->find('proj-merkwell'));
    }

    #[Test]
    public function tick_and_untick_from_the_ui_recalculate_status(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);
        $this->assertSame(3, $this->derived()['level']);                       // oldest 80h = 3d red

        $this->post(sprintf($this->url, 'in-a'), ['resolved' => true])->assertStatus(303);
        $a = RequiredInput::find('in-a');
        $this->assertSame('tilen', $a->resolved_by);
        $this->assertTrue($a->resolved_at->equalTo(now()));
        $this->assertSame(['status' => 'waiting', 'level' => 2, 'ageDays' => 2], $this->derived());

        $this->post(sprintf($this->url, 'in-b'), ['resolved' => true]);
        $this->post(sprintf($this->url, 'in-c'), ['resolved' => true]);
        $this->assertSame('smooth', $this->derived()['status']);

        $this->post(sprintf($this->url, 'in-a'), ['resolved' => false])->assertStatus(303);
        $a->refresh();
        $this->assertNull($a->resolved_at);
        $this->assertNull($a->resolved_by);
        $this->assertSame(3, $this->derived()['level']);

        $this->get('/projects/merkwell')->assertInertia(fn (Assert $p) => $p
            ->where('project.status', 'waiting')->has('openInputs', 1)->has('doneInputs', 2)
            ->where('doneInputs.0.resolvedByLabel', 'Tilen'));
    }

    #[Test]
    public function ui_route_requires_the_gate_and_scopes_to_the_project(): void
    {
        $this->post(sprintf($this->url, 'in-a'), ['resolved' => true])->assertRedirect(route('gate.show'));
        $this->assertNull(RequiredInput::find('in-a')->resolved_at);

        $this->withSession(['bridge_gate_unlocked' => true]);
        $this->post('/projects/concierge-avant/inputs/in-a/resolve', ['resolved' => true])->assertNotFound();
        $this->post(sprintf($this->url, 'in-a'), [])->assertSessionHasErrors('resolved');
    }

    #[Test]
    public function ui_route_is_csrf_protected(): void
    {
        $this->assertContains(PreventRequestForgery::class, app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups()['web']);
        // Laravel skips CSRF in unit tests; enforce it for this test.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->withSession(['bridge_gate_unlocked' => true, '_token' => 'good-token']);

        $this->post(sprintf($this->url, 'in-a'), ['resolved' => true])->assertStatus(419);
        $this->post(sprintf($this->url, 'in-a'), ['resolved' => true, '_token' => 'wrong'])->assertStatus(419);
        $this->assertNull(RequiredInput::find('in-a')->resolved_at);

        $this->post(sprintf($this->url, 'in-a'), ['resolved' => true], ['X-CSRF-TOKEN' => 'good-token'])->assertStatus(303);
        $this->assertSame('tilen', RequiredInput::find('in-a')->resolved_by);
    }

    #[Test]
    public function api_requires_a_token(): void
    {
        $this->getJson('/api/inputs')->assertUnauthorized();
        $this->patchJson('/api/inputs/in-a', ['resolved' => true])->assertUnauthorized();
    }

    #[Test]
    public function api_resolves_with_token_or_agent_name_and_filters(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('william-bot');
        $h = ['Authorization' => 'Bearer '.$token->plainTextToken];

        $this->patchJson('/api/inputs/in-a', ['resolved' => true], $h)->assertOk()
            ->assertJsonPath('data.resolved_by', 'token:william-bot');
        $this->postJson('/api/status-updates', ['project_id' => 'proj-merkwell', 'agent_id' => 'agent-anatoly', 'body' => 'b', 'resolved_inputs' => ['in-b']], $h)->assertCreated();
        $this->assertSame('Anatoly Korenchkin', RequiredInput::find('in-b')->resolved_by);

        Carbon::setTestNow(now()->addHour());
        $this->withSession(['bridge_gate_unlocked' => true])->post(sprintf($this->url, 'in-c'), ['resolved' => true]);
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/inputs?state=done', $h)->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'resolved_by', 'resolved_at']]]);
        $this->getJson('/api/inputs?resolved_by=tilen', $h)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'in-c');
        $this->getJson('/api/inputs?since='.urlencode(now()->subMinutes(5)->toIso8601String()), $h)->assertJsonCount(1, 'data');
        $this->getJson('/api/inputs?state=open&project_id=proj-merkwell', $h)->assertJsonCount(0, 'data');
        $this->getJson('/api/inputs?state=bogus', $h)->assertUnprocessable();

        $this->patchJson('/api/inputs/in-c', ['done' => false], $h)->assertOk()
            ->assertJsonPath('data.resolved_by', null)->assertJsonPath('data.resolved_at', null);
        $this->getJson('/api/inputs?state=open', $h)->assertJsonCount(2, 'data'); // in-c + seeded in-ca-1
    }
}
