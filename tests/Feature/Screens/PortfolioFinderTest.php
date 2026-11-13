<?php

namespace Tests\Feature\Screens;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\Workstream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/**
 * Portfolio Finder (portfolio-workstreams README §1.1, §3.1): selection in the URL (/portfolio?project=…), column 2
 * rows, the preview loaded for the selection only, New project / rename / delete (soft) / archived projects.
 */
class PortfolioFinderTest extends TestCase
{
    use RefreshDatabase;
    use TicketHelpers;

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

    #[Test]
    public function the_selection_lives_in_the_url_and_only_it_gets_a_preview(): void
    {
        $this->asOwner()->get('/portfolio?project=blue-harbor')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Portfolio')
            ->where('selected', 'blue-harbor')
            ->where('preview.slug', 'blue-harbor')
            ->where('projects', fn ($projects) => collect($projects)->every(fn ($row) => count($row['workstreams']) === 1
                && $row['workstreams'][0]['name'] === 'General' && $row['workstreams'][0]['isDefault'] === true)));

        $this->get('/portfolio')->assertInertia(fn (Assert $p) => $p->where('selected', null)->where('preview', null));
        $this->get('/portfolio?project=nope')->assertInertia(fn (Assert $p) => $p->where('selected', 'nope')->where('preview', null));
        // `/` is still the Portfolio for a signed-in user.
        $this->get('/?project=northwind')->assertInertia(fn (Assert $p) => $p->component('Portfolio')->where('selected', 'northwind'));
    }

    #[Test]
    public function workstream_rows_carry_ticket_counts_for_you_and_all_done(): void
    {
        $this->api()->newTicket();
        $this->waitingTicket();
        $this->newTicket(['status' => 'done']);

        $this->asOwner()->get('/portfolio')->assertInertia(fn (Assert $p) => $p->where('projects', function ($projects) {
            $row = collect($projects)->firstWhere('id', self::BH)['workstreams'][0];
            $this->assertSame([3, 1, 2, false], [$row['tickets'], $row['forYou'], $row['open'], $row['allDone']]);

            return true;
        }));
    }

    #[Test]
    public function new_project_needs_a_unique_name_of_at_most_60_and_gets_general(): void
    {
        $this->asOwner()->postJson('/projects', ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/projects', ['name' => str_repeat('x', 61)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/projects', ['name' => 'blue harbor'])->assertUnprocessable()->assertJsonValidationErrors('name');

        $data = $this->postJson('/projects', ['name' => 'Podcast site'])->assertCreated()
            ->assertJsonPath('data.slug', 'podcast-site')->assertJsonPath('data.url', '/portfolio?project=podcast-site')->json('data');
        $this->assertSame(['General'], Workstream::query()->where('project_id', $data['id'])->pluck('name')->all());
        $this->assertNotEmpty($data['ticket_prefix']);

        // An archived project's name is free again ("unique among active projects").
        $this->postJson('/projects/podcast-site/archive')->assertOk();
        $this->postJson('/projects', ['name' => 'Podcast site'])->assertCreated()->assertJsonPath('data.slug', 'podcast-site-2');
    }

    #[Test]
    public function rename_keeps_the_slug_and_follows_the_same_rules(): void
    {
        $this->asOwner()->patchJson('/projects/blue-harbor', ['name' => 'Northwind'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson('/projects/blue-harbor', ['name' => 'Blue Harbor app'])->assertOk()
            ->assertJsonPath('data.name', 'Blue Harbor app')->assertJsonPath('data.slug', 'blue-harbor');
        $this->postJson('/projects/blue-harbor/archive')->assertOk();
        $this->patchJson('/projects/blue-harbor', ['name' => 'Again'])->assertStatus(409);
    }

    #[Test]
    public function delete_needs_the_typed_name_and_is_a_soft_delete(): void
    {
        $ticket = $this->api()->newTicket();

        $this->asOwner()->deleteJson('/projects/blue-harbor', ['confirm_name' => 'Blue'])->assertUnprocessable()->assertJsonValidationErrors('confirm_name');
        $name = Project::query()->findOrFail(self::BH)->name;
        $this->deleteJson('/projects/blue-harbor', ['confirm_name' => $name])->assertOk()->assertJsonPath('data.deleted', true);

        $this->assertSoftDeleted('projects', ['id' => self::BH]);
        $this->assertDatabaseHas('tickets', ['key' => $ticket['key']]);
        $this->assertSame(1, Workstream::query()->where('project_id', self::BH)->count(), 'workstreams are kept');
        $this->get('/portfolio')->assertInertia(fn (Assert $p) => $p->where('projects', fn ($projects) => ! collect($projects)->contains('id', self::BH)));
        $this->get('/projects/blue-harbor')->assertNotFound();
        $this->api()->getJson('/api/tickets')->assertOk()->assertJsonCount(0, 'data');

        // Recoverable.
        Project::withTrashed()->findOrFail(self::BH)->restore();
        $this->assertSame(1, Ticket::query()->where('project_id', self::BH)->count());
    }

    #[Test]
    public function archived_projects_come_with_their_flag_and_no_badges(): void
    {
        $this->asOwner()->postJson('/projects/northwind/archive')->assertOk();

        $this->get('/portfolio?project=northwind')->assertInertia(fn (Assert $p) => $p
            ->where('preview.total', 0)
            ->where('projects', function ($projects) {
                $nw = collect($projects)->firstWhere('id', self::NW);
                $this->assertTrue($nw['archived']);
                $this->assertFalse($nw['hasChanges']);
                // Archived projects sort last (their own section in column 1).
                $this->assertSame(self::NW, collect($projects)->last()['id']);

                return true;
            }));
    }

    #[Test]
    public function finder_writes_need_a_login(): void
    {
        $this->postJson('/projects', ['name' => 'X'])->assertUnauthorized();
        $this->patchJson('/projects/blue-harbor', ['name' => 'X'])->assertUnauthorized();
        $this->deleteJson('/projects/blue-harbor', ['confirm_name' => 'X'])->assertUnauthorized();
        $this->assertNotSoftDeleted('projects', ['id' => self::BH]);
    }
}
