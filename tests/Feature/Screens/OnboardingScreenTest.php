<?php

namespace Tests\Feature\Screens;

use App\Models\User;
use App\Services\Auth\AccountService;
use App\Support\BoardColumns;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/** GET /welcome: the checklist state is derived from the own workspace (project, token), nothing stored. */
class OnboardingScreenTest extends TestCase
{
    use RefreshDatabase;
    use TicketHelpers;

    private User $sam;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->sam = app(AccountService::class)->register('Sam Rivera', 'sam@example.test', 'garden-planner-27')->fresh();
    }

    #[Test]
    public function a_new_user_starts_empty_even_when_other_workspaces_have_projects(): void
    {
        $this->bootTickets();

        $this->actingAs($this->sam)->get('/welcome')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Onboarding')
            ->where('name', 'Sam Rivera')
            ->where('project', null)
            ->where('hasToken', false)
            ->where('emailVerified', false)
            ->where('columns', BoardColumns::all())
            ->where('defaultColumns', BoardColumns::all())
            ->where('createProjectUrl', route('projects.store'))
            // no project yet: step 1's answer carries the URL
            ->where('saveColumnsUrl', null));
    }

    #[Test]
    public function the_owner_sees_his_first_project_and_token(): void
    {
        $this->bootTickets();
        $tilen = app(CurrentWorkspace::class)->default()->owner;
        $tilen->createToken('captain', ['ceo']);

        $this->actingAs($tilen)->get('/welcome')->assertInertia(fn (Assert $p) => $p
            ->where('project', fn ($pr) => $pr !== null && array_keys($pr->toArray()) === ['name', 'slug'])
            ->where('saveColumnsUrl', fn ($url) => str_ends_with((string) $url, '/column-labels'))
            ->where('hasToken', true));
    }

    #[Test]
    public function guests_and_gate_sessions_go_to_login(): void
    {
        $this->get('/welcome')->assertRedirect('/login');
        $this->withSession(['bridge_gate_unlocked' => true])->get('/welcome')->assertRedirect('/login');
    }
}
