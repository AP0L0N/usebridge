<?php

namespace Tests\Feature\Screens;

use App\Models\Project;
use App\Models\User;
use App\Services\Auth\AccountService;
use App\Support\BoardColumns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The first-project onboarding (Figma "Onboarding"): header + stepper copy, step 1 (create), step 2 (the BoardColumns
 * set, "Waiting on <owner's first name>", inline rename) and step 3 (MCP URL from APP_URL, tokens link). Done: OnboardingDoneTest.
 */
class OnboardingFlowTest extends TestCase
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

    private function labels(User $user, string $slug, mixed $labels): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user)->putJson("/projects/$slug/column-labels", ['column_labels' => $labels]);
    }

    #[Test]
    public function every_step_has_the_header_and_the_stepper_copy(): void
    {
        $this->actingAs($this->sam)->get('/welcome')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Onboarding')
            ->where('name', 'Sam Rivera')
            ->where('copy.overline', 'Step :n of 3')
            ->where('copy.heading', 'Welcome to Bridge, :name')
            ->where('copy.subline', 'Three quick steps to your first board. You can change everything later.')
            ->where('copy.stepper.steps', ['Project', 'Board', 'Connect your bot']));
    }

    #[Test]
    public function step_1_creates_the_project_with_the_lead_shown_as_you(): void
    {
        $this->actingAs($this->sam)->get('/welcome')->assertInertia(fn (Assert $p) => $p
            ->where('project', null)
            ->where('createProjectUrl', route('projects.store'))
            ->where('copy.project.title', 'Create your first project')
            ->where('copy.project.name', 'Project name')
            ->where('copy.project.description', 'Short description (optional)')
            ->where('copy.project.description_help', 'Shown on the Portfolio card. One line is enough.')
            ->where('copy.project.lead', 'Project lead')
            ->where('copy.project.lead_you', 'You')
            ->where('copy.project.lead_help', 'The lead sets the project status. An agent can take over once your bot is connected.')
            ->where('copy.project.submit', 'Create project'));

        $this->createProject($this->sam, ['description' => 'One line'])->assertCreated()
            ->assertJsonPath('data.slug', 'example-project')
            ->assertJsonPath('data.ticket_prefix', 'EP');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->sam)->get('/welcome')->assertInertia(fn (Assert $p) => $p
            ->where('project', ['name' => 'Example project', 'slug' => 'example-project'])
            ->where('saveColumnsUrl', route('projects.column-labels.update', 'example-project')));
    }

    #[Test]
    public function step_1_says_clearly_when_a_slug_or_prefix_is_taken_anywhere(): void
    {
        $other = app(AccountService::class)->register('Bea Beacon', 'bea@example.test', 'beacon-works-planner-27')->fresh();
        $this->createProject($other)->assertCreated();

        $this->createProject($this->sam)->assertStatus(422)
            ->assertJsonPath('errors.slug.0', 'This slug is already taken.')
            ->assertJsonPath('errors.ticket_prefix.0', 'This ticket prefix is already taken.');
        $this->createProject($this->sam, ['slug' => 'Not A Slug!', 'ticket_prefix' => '1X'])->assertStatus(422)
            ->assertJsonPath('errors.slug.0', 'Use lowercase letters, digits and single dashes (e.g. garden-planner).')
            ->assertJsonPath('errors.ticket_prefix.0', 'Use 1–8 capital letters or digits, starting with a letter (e.g. GP).');
    }

    #[Test]
    public function step_2_lists_the_six_board_columns_in_order_with_waiting_on_the_owner(): void
    {
        $expected = [
            ['key' => 'backlog', 'label' => 'Backlog', 'order' => 0],
            ['key' => 'todo', 'label' => 'To do', 'order' => 1],
            ['key' => 'in_progress', 'label' => 'In progress', 'order' => 2],
            ['key' => 'waiting_tilen', 'label' => 'Waiting on Sam', 'order' => 3],
            ['key' => 'review', 'label' => 'Review / QA', 'order' => 4],
            ['key' => 'done', 'label' => 'Done', 'order' => 5],
        ];
        $this->assertSame($expected, BoardColumns::all(null, $this->sam->homeWorkspace()));
        $this->assertSame(array_column($expected, 'key'), BoardColumns::keys());

        $this->createProject($this->sam)->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->sam)->get('/welcome')->assertInertia(fn (Assert $p) => $p
            ->where('columns', $expected)
            ->where('defaultColumns', $expected)
            ->where('copy.board.title', 'Set up its board')
            ->where('copy.board.sub', 'Start with these columns or rename them. Tickets move left to right.')
            ->where('copy.board.core', 'Core column')
            ->where('copy.board.back', 'Back')
            ->where('copy.board.save', 'Save board'));

        // No "Add column" / drag reorder: the column set and order are fixed by BoardColumns.
        $page = (string) file_get_contents(resource_path('js/Pages/Onboarding.tsx'));
        $this->assertStringNotContainsString('Add column', json_encode(__('onboarding'), JSON_UNESCAPED_UNICODE));
        $this->assertDoesNotMatchRegularExpression('/draggable|onDrag/', $page);
        $this->assertStringContainsString("const CORE = 'waiting_tilen';", $page);
    }

    #[Test]
    public function an_existing_project_keeps_its_stored_column_labels(): void
    {
        $this->createProject($this->sam)->assertCreated();
        Project::acrossWorkspaces()->where('slug', 'example-project')->firstOrFail()
            ->forceFill(['column_labels' => ['waiting_tilen' => 'Needs Sam']])->save();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->sam)->get('/welcome')->assertInertia(fn (Assert $p) => $p
            ->where('columns.3', ['key' => 'waiting_tilen', 'label' => 'Needs Sam', 'order' => 3])
            ->where('defaultColumns.3.label', 'Waiting on Sam'));
    }

    #[Test]
    public function step_2_renames_columns_inline_and_validates_them(): void
    {
        $this->createProject($this->sam)->assertCreated();

        $this->labels($this->sam, 'example-project', ['backlog' => '  Ideas  ', 'waiting_tilen' => 'Needs Sam'])->assertOk()
            ->assertJsonPath('data.columns.0', ['key' => 'backlog', 'label' => 'Ideas', 'order' => 0])
            ->assertJsonPath('data.columns.3', ['key' => 'waiting_tilen', 'label' => 'Needs Sam', 'order' => 3]);

        $this->labels($this->sam, 'example-project', ['todo' => '   '])->assertStatus(422)
            ->assertJsonPath('errors', ['column_labels.todo' => ['Give every column a name.']]);
        $this->labels($this->sam, 'example-project', ['todo' => str_repeat('x', BoardColumns::LABEL_MAX + 1)])->assertStatus(422)
            ->assertJsonPath('errors', ['column_labels.todo' => ['Column names are at most 40 characters.']]);
        $this->labels($this->sam, 'example-project', ['someday' => 'Someday'])->assertStatus(422)->assertJsonValidationErrors('column_labels');

        // The default name clears the own label again.
        $this->labels($this->sam, 'example-project', ['backlog' => 'Backlog', 'waiting_tilen' => 'Waiting on Sam'])->assertOk();
        $this->assertNull(Project::acrossWorkspaces()->where('slug', 'example-project')->value('column_labels'));
    }

    #[Test]
    public function step_3_builds_the_mcp_url_from_app_url_and_links_the_tokens_page(): void
    {
        config(['app.url' => 'https://boards.example.test']);
        URL::forceRootUrl('https://boards.example.test');
        URL::forceScheme('https');

        $this->actingAs($this->sam)->get('/welcome')->assertInertia(fn (Assert $p) => $p
            ->where('mcpUrl', url('/mcp'))
            ->where('mcpUrl', 'https://boards.example.test/mcp')
            ->where('tokensUrl', route('account.tokens', absolute: false))
            ->where('portfolioUrl', '/')
            ->where('copy.bot.tag', 'MCP · optional')
            ->where('copy.bot.title', 'Connect your bot')
            ->where('copy.bot.steps.0.title', 'Create a token')
            ->where('copy.bot.steps.1.body', 'Paste the server URL :url and your token as the auth header.')
            ->where('copy.bot.steps.2.body', 'It should answer with :project.')
            ->where('copy.bot.skip', 'Skip for now')
            ->where('copy.bot.later', 'You can do this later in Account, API tokens & MCP.'));
    }

    #[Test]
    public function the_mcp_url_follows_app_url_not_the_request_host(): void
    {
        config(['app.url' => 'https://boards.example.test/']);

        $this->actingAs($this->sam)->get('http://other-host.example.test/welcome')->assertInertia(fn (Assert $p) => $p
            ->where('mcpUrl', 'https://boards.example.test/mcp'));
        $this->assertStringNotContainsString('https://', (string) file_get_contents(resource_path('js/Pages/Onboarding.tsx')));
    }
}
