<?php

namespace Tests\Feature\MultiUser;

use App\Models\Project;
use App\Models\User;
use App\Services\Auth\AccountService;
use App\Support\BoardColumns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/** Web POST /projects and PUT /projects/{slug}/column-labels (docs/MULTI-USER-PLAN.md §8 onboarding steps 1–2). */
class ProjectSetupTest extends TestCase
{
    use RefreshDatabase;
    use TicketHelpers;

    private User $ada;

    private User $bea;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $accounts = app(AccountService::class);
        $this->ada = $accounts->register('Ada Acme', 'ada@example.test', 'acme-studio-planner-27')->fresh();
        $this->bea = $accounts->register('Bea Beacon', 'bea@example.test', 'beacon-works-planner-27')->fresh();
    }

    private function create(User $user, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user)->postJson('/projects', ['name' => 'Acme Studio', 'slug' => 'acme-studio', 'ticket_prefix' => 'AC', ...$data]);
    }

    private function labels(User $user, string $slug, mixed $labels): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user)->putJson("/projects/$slug/column-labels", ['column_labels' => $labels]);
    }

    #[Test]
    public function a_new_user_creates_a_project_in_their_own_workspace_without_agents(): void
    {
        $this->create($this->ada, ['description' => '  Client site rebuild  '])->assertCreated()
            ->assertJsonPath('data.name', 'Acme Studio')
            ->assertJsonPath('data.slug', 'acme-studio')
            ->assertJsonPath('data.ticket_prefix', 'AC')
            ->assertJsonPath('data.description', 'Client site rebuild')
            ->assertJsonPath('data.column_labels_url', route('projects.column-labels.update', 'acme-studio'));

        $project = Project::acrossWorkspaces()->where('slug', 'acme-studio')->firstOrFail();
        $this->assertSame($this->ada->homeWorkspace()->id, $project->workspace_id);
        $this->assertNull($project->owner_agent_id);

        // On Ada's Portfolio and board; not on Bea's.
        $this->actingAs($this->ada)->get('/')->assertInertia(fn (Assert $p) => $p
            ->component('Portfolio')->has('projects', 1)->where('projects.0.name', 'Acme Studio')->where('projects.0.pmName', null));
        $this->actingAs($this->ada)->get('/projects/acme-studio')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('board.project.description', 'Client site rebuild')->where('board.project.pm_agent_id', null));
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->bea)->get('/')->assertInertia(fn (Assert $p) => $p->has('projects', 0));
        $this->actingAs($this->bea)->get('/projects/acme-studio')->assertNotFound();

        // Onboarding now starts at step 2 for that project.
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->ada)->get('/welcome')->assertInertia(fn (Assert $p) => $p
            ->where('project.slug', 'acme-studio')
            ->where('saveColumnsUrl', route('projects.column-labels.update', 'acme-studio')));
    }

    #[Test]
    public function slug_and_prefix_are_normalised_and_validated(): void
    {
        $this->create($this->ada, ['name' => '', 'slug' => 'Bad Slug!', 'ticket_prefix' => '1x'])->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'slug', 'ticket_prefix']);
        $this->create($this->ada, ['slug' => ' Acme-Studio ', 'ticket_prefix' => 'ac'])->assertCreated()
            ->assertJsonPath('data.slug', 'acme-studio')->assertJsonPath('data.ticket_prefix', 'AC');
    }

    #[Test]
    public function a_slug_or_prefix_taken_in_another_workspace_is_a_422_that_does_not_say_where(): void
    {
        $this->create($this->bea)->assertCreated();

        $slug = $this->create($this->ada, ['ticket_prefix' => 'ACS'])->assertStatus(422)
            ->assertJsonValidationErrors(['slug' => 'This slug is already taken.'])
            ->assertJsonMissingValidationErrors(['ticket_prefix']);
        $prefix = $this->create($this->ada, ['slug' => 'acme-studio-2'])->assertStatus(422)
            ->assertJsonValidationErrors(['ticket_prefix' => 'This ticket prefix is already taken.'])
            ->assertJsonMissingValidationErrors(['slug']);
        foreach ([$slug, $prefix] as $res) {
            $body = $res->getContent();
            $this->assertStringNotContainsStringIgnoringCase('Bea', $body);
            $this->assertStringNotContainsString((string) $this->bea->homeWorkspace()->slug, $body);
        }
        $this->assertSame(1, Project::acrossWorkspaces()->count());
    }

    #[Test]
    public function guests_and_gate_sessions_cannot_create_or_rename(): void
    {
        $this->postJson('/projects', ['name' => 'Acme Studio', 'slug' => 'acme-studio', 'ticket_prefix' => 'AC'])->assertUnauthorized();
        $this->gate()->postJson('/projects', ['name' => 'Acme Studio', 'slug' => 'acme-studio', 'ticket_prefix' => 'AC'])->assertUnauthorized();
        $this->assertSame(0, Project::acrossWorkspaces()->count());
    }

    #[Test]
    public function column_labels_are_saved_per_project_and_reflected_on_the_board_and_portfolio(): void
    {
        $this->create($this->ada)->assertCreated();

        $this->labels($this->ada, 'acme-studio', ['backlog' => '  Ideas ', 'waiting_tilen' => 'Waiting on client', 'done' => 'Done'])->assertOk()
            ->assertJsonPath('data.columns.0', ['key' => 'backlog', 'label' => 'Ideas', 'order' => 0])
            ->assertJsonPath('data.columns.3.label', 'Waiting on client');
        $project = Project::acrossWorkspaces()->where('slug', 'acme-studio')->firstOrFail();
        // A label equal to the default is not stored.
        $this->assertSame(['backlog' => 'Ideas', 'waiting_tilen' => 'Waiting on client'], $project->column_labels);

        // Keys not sent keep their label.
        $this->labels($this->ada, 'acme-studio', ['review' => 'Client review'])->assertOk();
        $this->assertSame(['backlog' => 'Ideas', 'waiting_tilen' => 'Waiting on client', 'review' => 'Client review'], $project->fresh()->column_labels);

        $this->actingAs($this->ada)->get('/projects/acme-studio')->assertInertia(fn (Assert $p) => $p
            ->where('board.columns.0.key', 'backlog')
            ->where('board.columns.0.label', 'Ideas')
            ->where('board.columns.1.label', BoardColumns::label('todo'))
            ->where('board.columns.3.label', 'Waiting on client')
            ->where('board.columns.4.label', 'Client review')
            ->where('columnLabelsUrl', route('projects.column-labels.update', 'acme-studio'))
            ->where('defaultColumns', BoardColumns::all()));
        $this->actingAs($this->ada)->get('/')->assertInertia(fn (Assert $p) => $p
            ->where('projects.0.columnLabels.backlog', 'Ideas'));
        $this->actingAs($this->ada)->get('/welcome')->assertInertia(fn (Assert $p) => $p->where('columns.0.label', 'Ideas'));
    }

    #[Test]
    public function column_labels_are_validated(): void
    {
        $this->create($this->ada)->assertCreated();

        $this->labels($this->ada, 'acme-studio', ['backlog' => 'Ideas', 'later' => 'Later'])->assertStatus(422)
            ->assertJsonValidationErrors(['column_labels' => 'Unknown column: later.']);
        $this->labels($this->ada, 'acme-studio', ['backlog' => '   '])->assertStatus(422)
            ->assertJsonValidationErrors(['column_labels.backlog' => 'Give every column a name.']);
        $this->labels($this->ada, 'acme-studio', ['todo' => str_repeat('x', 41)])->assertStatus(422)
            ->assertJsonValidationErrors(['column_labels.todo' => 'at most 40 characters']);
        $this->labels($this->ada, 'acme-studio', ['todo' => ['nested']])->assertStatus(422)->assertJsonValidationErrors(['column_labels.todo']);
        $this->labels($this->ada, 'acme-studio', [])->assertStatus(422)->assertJsonValidationErrors(['column_labels']);
        $this->labels($this->ada, 'acme-studio', 'Ideas')->assertStatus(422)->assertJsonValidationErrors(['column_labels']);
        $this->labels($this->ada, 'acme-studio', ['todo' => str_repeat('x', 40)])->assertOk();

        // Only the valid save landed.
        $this->assertSame(['todo' => str_repeat('x', 40)], Project::acrossWorkspaces()->where('slug', 'acme-studio')->first()->column_labels);
    }

    #[Test]
    public function another_workspaces_project_is_a_404_and_unchanged(): void
    {
        $this->create($this->bea)->assertCreated();

        $this->labels($this->ada, 'acme-studio', ['backlog' => 'Mine now'])->assertNotFound();
        $this->labels($this->ada, 'no-such-project', ['backlog' => 'Mine now'])->assertNotFound();
        $this->assertNull(Project::acrossWorkspaces()->where('slug', 'acme-studio')->first()->column_labels);

        // A gate session (not a workspace member) cannot rename either, and its board has no editor.
        $this->bootTickets();
        $this->app['auth']->forgetGuards();
        $slug = Project::acrossWorkspaces()->where('id', self::CA)->value('slug');
        $this->gate()->putJson("/projects/$slug/column-labels", ['column_labels' => ['backlog' => 'Ideas']])->assertUnauthorized();
        $this->gate()->get("/projects/$slug")->assertInertia(fn (Assert $p) => $p->where('columnLabelsUrl', null));
    }

    #[Test]
    public function rest_project_creation_is_unchanged(): void
    {
        $this->bootTickets();
        $this->api();

        // owner_agent_id stays required on the API path.
        $this->postJson('/api/projects', ['name' => 'Acme Studio', 'slug' => 'acme-studio'])->assertStatus(422)
            ->assertJsonValidationErrors(['owner_agent_id']);
        $res = $this->postJson('/api/projects', ['name' => 'Acme Studio', 'slug' => 'acme-studio', 'owner_agent_id' => self::JANICE])->assertCreated()
            ->assertJsonPath('data.owner_agent_id', self::JANICE)
            ->assertJsonPath('data.ticket_prefix', 'AS');
        $this->assertArrayNotHasKey('description', $res->json('data'));
        $this->assertArrayNotHasKey('column_labels', $res->json('data'));
        $this->assertArrayNotHasKey('columnLabels', $res->json('data'));
        // The API's global unique rules answer as before.
        $this->postJson('/api/projects', ['name' => 'Acme Studio', 'slug' => 'acme-studio', 'owner_agent_id' => self::JANICE])->assertStatus(422)
            ->assertJsonValidationErrors(['slug' => 'The slug has already been taken.']);
    }
}
