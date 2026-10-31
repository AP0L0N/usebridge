<?php

namespace Tests\Feature\Screens;

use App\Models\User;
use App\Services\Auth\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The onboarding's done view: GET /?ready={slug} on the Portfolio (banner; connect card only without an API token). */
class OnboardingDoneTest extends TestCase
{
    use RefreshDatabase;

    private User $sam;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->sam = app(AccountService::class)->register('Sam Rivera', 'sam@example.test', 'garden-planner-27')->fresh();
    }

    private function createProject(User $user, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user)->postJson('/projects', ['name' => 'Example project', 'slug' => 'example-project', 'ticket_prefix' => 'EP', ...$data]);
    }

    #[Test]
    public function done_shows_the_banner_and_the_connect_card_only_without_a_token(): void
    {
        $this->createProject($this->sam)->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->sam)->get('/?ready=example-project')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Portfolio')
            ->where('welcome.project', 'Example project')
            ->where('welcome.connect', true)
            ->where('welcome.tokensUrl', '/account/tokens')
            ->where('welcome.copy.ready', ':project is ready. Open it to add your first tickets.')
            ->where('welcome.copy.connect', 'Connect your bot so it can read this board and move tickets.')
            ->where('welcome.copy.connect_link', 'Set up API tokens & MCP →'));

        // Without ?ready the Portfolio is unchanged.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->sam)->get('/')->assertInertia(fn (Assert $p) => $p->where('welcome', null));

        // With a token: the banner stays, the connect card goes.
        $this->sam->createToken('bot', ['read']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->sam)->get('/?ready=example-project')->assertInertia(fn (Assert $p) => $p
            ->where('welcome.project', 'Example project')
            ->where('welcome.connect', false));
    }

    #[Test]
    public function done_never_names_another_workspaces_project(): void
    {
        $other = app(AccountService::class)->register('Bea Beacon', 'bea@example.test', 'beacon-works-planner-27')->fresh();
        $this->createProject($other, ['name' => 'Beacon board', 'slug' => 'beacon-board', 'ticket_prefix' => 'BB'])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->sam)->get('/?ready=beacon-board')->assertOk()->assertInertia(fn (Assert $p) => $p->where('welcome', null));
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->sam)->get('/?ready=nope')->assertInertia(fn (Assert $p) => $p->where('welcome', null));
    }
}
