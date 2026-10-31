<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectStatusEvent;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/** Tilen's own project controls (web session, never the token API): status + note, archive/unarchive, all logged. */
class TilenProjectControlsTest extends TestCase
{
    use RefreshDatabase;
    use TicketHelpers;

    private const SLUG = 'blue-harbor';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function row(string $id = self::BH): array
    {
        return collect($this->getJson('/api/projects')->assertOk()->json('data'))->firstWhere('id', $id);
    }

    /** @return list<ProjectStatusEvent> */
    private function events(): array
    {
        return ProjectStatusEvent::query()->where('project_id', self::BH)->orderBy('created_at')->orderBy('id')->get()->all();
    }

    #[Test]
    public function tilen_sets_status_with_a_note_via_the_web_and_it_is_logged_and_listed_as_tilen(): void
    {
        $this->asOwner()->patchJson('/projects/'.self::SLUG.'/status', ['status' => 'waiting', 'note' => 'Cleared the tickets'])
            ->assertOk()->assertJsonPath('data.current.status', 'waiting')->assertJsonPath('data.current.set_by', 'tilen');

        $e = collect($this->events())->last();
        $this->assertSame(['waiting', 'Cleared the tickets', 'tilen', 'human', 'ui'], [$e->to_status, $e->note, $e->set_by, $e->set_by_type, $e->source]);

        $this->api();
        $row = $this->row();
        $this->assertSame(['waiting', 'tilen', 'waiting', 'Cleared the tickets'], [$row['status'], $row['status_source'], $row['pmStatus'], $row['pmStatusNote']]);
        $this->assertSame(1, $row['level']);

        $this->asOwner()->patchJson('/projects/'.self::SLUG.'/status', ['status' => 'smooth'])->assertOk();
        $row = $this->row();
        $this->assertSame(['smooth', 'tilen', null], [$row['status'], $row['status_source'], $row['pmStatusNote']]);
    }

    #[Test]
    public function a_later_pm_status_overrides_tilens_last_write_wins(): void
    {
        $this->asOwner()->patchJson('/projects/'.self::SLUG.'/status', ['status' => 'smooth', 'note' => 'All clear'])->assertOk();
        $this->api();
        $this->assertSame('tilen', $this->row()['status_source']);

        $this->patchJson('/api/projects/'.self::BH.'/status', ['status' => 'waiting', 'note' => 'Need the logo', 'actor' => self::JANICE])->assertOk();
        $row = $this->row();
        $this->assertSame(['waiting', 'pm', 'Need the logo'], [$row['status'], $row['status_source'], $row['pmStatusNote']]);

        $this->asOwner()->patchJson('/projects/'.self::SLUG.'/status', ['status' => 'smooth'])->assertOk();
        $this->assertSame('tilen', $this->row()['status_source']);
        $this->assertSame(['tilen', self::JANICE, 'tilen'], array_map(fn ($e) => $e->set_by, $this->events()));
    }

    #[Test]
    public function never_set_projects_are_derived(): void
    {
        $this->api();
        $this->assertSame('derived', $this->row(self::NW)['status_source']);
    }

    #[Test]
    public function portfolio_groups_a_tilen_set_waiting_project_with_the_waiting_ones(): void
    {
        $this->asOwner()->patchJson('/projects/'.self::SLUG.'/status', ['status' => 'waiting'])->assertOk();
        $this->asOwner()->get('/')->assertOk()->assertInertia(function ($page) {
            $p = collect($page->toArray()['props']['projects'])->firstWhere('slug', self::SLUG);
            $this->assertSame(['waiting', 'tilen', 1], [$p['pmStatus'], $p['pmStatusSetBy'], $p['pmLevel']]);
            $this->assertSame(self::BH, collect($page->toArray()['props']['projects'])->first()['id'], 'waiting group sorts first');
        });
    }

    #[Test]
    public function note_over_280_chars_is_rejected_and_nothing_is_logged(): void
    {
        $before = count($this->events());
        $this->asOwner()->patchJson('/projects/'.self::SLUG.'/status', ['status' => 'smooth', 'note' => str_repeat('x', 281)])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->asOwner()->patchJson('/projects/'.self::SLUG.'/status', ['status' => 'smooth', 'note' => str_repeat('x', 280)])->assertOk();
        $this->assertSame($before + 1, count($this->events()));
    }

    #[Test]
    public function archive_and_unarchive_via_the_web_are_logged_and_delete_nothing(): void
    {
        $this->api();
        $t = $this->newTicket();
        $this->postJson("/api/tickets/{$t['key']}/comments", ['body' => 'hi', 'actor' => self::JANICE])->assertCreated();
        $this->asOwner()->patchJson('/projects/'.self::SLUG.'/status', ['status' => 'waiting', 'note' => 'n'])->assertOk();
        $counts = fn () => [Project::count(), Ticket::count(), TicketComment::count(), TicketEvent::count()];
        $before = $counts();

        $this->asOwner()->postJson('/projects/'.self::SLUG.'/archive')->assertOk()->assertJsonPath('data.current.status', 'archived');
        $p = Project::find(self::BH);
        $this->assertNotNull($p->archived_at);
        $this->assertSame('archived', $p->status);
        $e = collect($this->events())->last();
        $this->assertSame(['waiting', 'archived', 'tilen', 'human', 'ui'], [$e->from_status, $e->to_status, $e->set_by, $e->set_by_type, $e->source]);
        $this->api();
        $this->assertSame(['archived', 'tilen'], [$this->row()['status'], $this->row()['status_source']]);

        // Archiving again is a no-op (no extra log row).
        $n = count($this->events());
        $this->asOwner()->postJson('/projects/'.self::SLUG.'/archive')->assertOk();
        $this->assertSame($n, count($this->events()));

        // The archived board still opens, and shows up in the portfolio's archived group.
        $this->asOwner()->get('/projects/'.self::SLUG)->assertOk();
        $this->asOwner()->get('/')->assertInertia(fn ($page) => $this->assertTrue(
            collect($page->toArray()['props']['projects'])->firstWhere('slug', self::SLUG)['archived'],
        ));

        $this->asOwner()->postJson('/projects/'.self::SLUG.'/unarchive')->assertOk()->assertJsonPath('data.current.status', 'waiting');
        $p = Project::find(self::BH);
        $this->assertNull($p->archived_at);
        $this->assertNull($p->reopened_at);
        $e = collect($this->events())->last();
        $this->assertSame(['archived', 'waiting', 'tilen', 'human', 'ui'], [$e->from_status, $e->to_status, $e->set_by, $e->set_by_type, $e->source]);
        $this->assertSame($before, $counts());
    }

    #[Test]
    public function unarchive_without_a_prior_status_goes_to_smooth(): void
    {
        $this->asOwner()->post('/projects/'.self::SLUG.'/archive')->assertRedirect();
        $this->asOwner()->post('/projects/'.self::SLUG.'/unarchive')->assertRedirect();
        $this->assertSame('smooth', Project::find(self::BH)->status);
        $this->assertCount(2, $this->events());
    }

    #[Test]
    public function web_controls_redirect_to_login_without_a_session(): void
    {
        $this->patch('/projects/'.self::SLUG.'/status', ['status' => 'smooth'])->assertRedirect(route('login'));
        $this->post('/projects/'.self::SLUG.'/archive')->assertRedirect(route('login'));
        $this->post('/projects/'.self::SLUG.'/unarchive')->assertRedirect(route('login'));
        $this->assertCount(0, $this->events());
    }

    #[Test]
    public function the_token_api_still_refuses_actor_tilen(): void
    {
        $this->api();
        $this->patchJson('/api/projects/'.self::BH.'/status', ['status' => 'smooth', 'actor' => 'tilen'])->assertUnprocessable();
        $this->patchJson('/api/projects/'.self::BH, ['archived' => true, 'actor' => 'tilen'])->assertUnprocessable()->assertJsonValidationErrors('actor');
        $this->postJson('/api/projects/'.self::BH.'/archive', ['actor' => 'tilen'])->assertUnprocessable();
        $this->assertNull(Project::find(self::BH)->archived_at);
        $this->assertNotContains('tilen', array_map(fn ($e) => $e->set_by, $this->events()));
    }

    #[Test]
    public function api_archive_with_an_agent_actor_is_logged_and_keeps_status_in_step(): void
    {
        $this->api()->postJson('/api/projects/'.self::BH.'/archive', ['actor' => self::CAPTAIN])->assertOk()->assertJsonPath('data.status', 'archived');
        $e = collect($this->events())->last();
        $this->assertSame([self::CAPTAIN, 'api'], [$e->set_by, $e->source]);
        $this->assertSame('pm', $this->row()['status_source']);
        $this->postJson('/api/projects/'.self::BH.'/unarchive', ['actor' => self::CAPTAIN])->assertOk();
        $this->assertSame(['smooth', null], [Project::find(self::BH)->status, Project::find(self::BH)->archived_at]);
    }
}
