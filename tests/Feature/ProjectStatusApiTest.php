<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectStatusEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

class ProjectStatusApiTest extends TestCase
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

    private function setStatus(array $body, string $project = self::CA)
    {
        return $this->patchJson("/api/projects/$project/status", $body);
    }

    #[Test]
    public function needs_the_token(): void
    {
        $this->getJson('/api/projects/'.self::CA.'/status')->assertUnauthorized();
        $this->patchJson('/api/projects/'.self::CA.'/status', ['status' => 'smooth'])->assertUnauthorized();
    }

    #[Test]
    public function pm_sets_status_and_note_and_every_write_is_an_event(): void
    {
        $this->api();
        $this->getJson('/api/projects/'.self::CA.'/status')->assertOk()
            ->assertJsonPath('data.current.status', null)->assertJsonPath('data.current.status_label', 'Status not set')->assertJsonPath('data.history', []);

        $r = $this->setStatus(['status' => 'smooth', 'note' => 'Copy locked, build on track.', 'actor' => self::JANICE])->assertOk();
        $r->assertJsonPath('data.current.status', 'smooth')->assertJsonPath('data.current.set_by', self::JANICE)
            ->assertJsonPath('data.current.set_by_name', 'Dr. Janice Polito')->assertJsonPath('data.event.from_status', null)
            ->assertJsonPath('data.event.source', 'api')->assertJsonPath('data.event.set_by_type', 'agent')->assertJsonPath('warnings', []);
        $this->assertStringStartsWith('pse-', $r->json('data.event.id'));
        $p = Project::find(self::CA);
        $this->assertSame(['smooth', 'Copy locked, build on track.', self::JANICE], [$p->status, $p->status_note, $p->status_set_by]);
        $this->assertSame(Carbon::now()->toIso8601String(), $p->status_set_at->toIso8601String());

        // Note-only update: still an event.
        $this->travelMinutes(10);
        $this->setStatus(['status' => 'smooth', 'note' => 'Still fine.', 'actor' => self::JANICE])->assertOk()->assertJsonPath('data.event.from_status', 'smooth');
        $this->travelMinutes(10);
        $this->setStatus(['status' => 'waiting', 'actor' => self::JANICE])->assertOk()->assertJsonPath('data.current.note', null);
        $this->assertSame(3, ProjectStatusEvent::count());

        $h = $this->getJson('/api/projects/'.self::CA.'/status')->assertOk()->json('data');
        $this->assertSame(['waiting', 'smooth', 'smooth'], array_column($h['history'], 'to_status'));
        $this->assertSame([null, 'Still fine.', 'Copy locked, build on track.'], array_column($h['history'], 'note'));
        $this->assertSame('Waiting input', $h['current']['status_label']);
    }

    #[Test]
    public function validation_and_permissions(): void
    {
        $this->api();
        $this->setStatus(['status' => 'blocked', 'actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->setStatus(['actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->setStatus(['status' => 'smooth', 'note' => str_repeat('n', 281), 'actor' => self::JANICE])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->setStatus(['status' => 'smooth'])->assertUnprocessable()->assertJsonValidationErrors('actor');
        $this->setStatus(['status' => 'smooth', 'actor' => 'tilen'])->assertUnprocessable()->assertJsonValidationErrors('actor');
        $this->setStatus(['status' => 'smooth', 'actor' => self::ANATOLY])->assertForbidden(); // not this project's PM
        $this->setStatus(['status' => 'archived', 'actor' => self::JANICE])->assertForbidden(); // PMs can't archive
        $this->patchJson('/api/projects/proj-nope/status', ['status' => 'smooth', 'actor' => self::JANICE])->assertNotFound();
        $this->assertSame(0, ProjectStatusEvent::count());

        // Captain archives and reopens; PMs can't touch an archived project's status.
        $this->setStatus(['status' => 'archived', 'note' => 'Wrapped.', 'actor' => self::CAPTAIN])->assertOk();
        $this->assertNotNull(Project::find(self::CA)->archived_at);
        $this->setStatus(['status' => 'smooth', 'actor' => self::JANICE])->assertForbidden();
        $this->postJson('/api/projects/'.self::CA.'/tickets', ['title' => 'x', 'actor' => self::JANICE])->assertStatus(409);
        $this->setStatus(['status' => 'smooth', 'actor' => self::CAPTAIN])->assertOk();
        $p = Project::find(self::CA);
        $this->assertNull($p->archived_at);
        $this->assertNotNull($p->reopened_at);
        // Captain may set any project's status.
        $this->setStatus(['status' => 'waiting', 'actor' => self::CAPTAIN], self::MW)->assertOk();
    }

    #[Test]
    public function warnings_when_smooth_but_tickets_wait_on_tilen(): void
    {
        $this->api();
        $t = $this->waitingTicket();
        $this->waitingTicket();
        $this->setStatus(['status' => 'smooth', 'actor' => self::JANICE])->assertOk()
            ->assertJsonPath('warnings.0.code', 'status_smooth_but_waiting_tickets')->assertJsonPath('warnings.0.count', 2);
        // Ticket writes carry the same warning.
        $this->postJson("/api/tickets/{$t['key']}/move", ['status' => 'in_progress', 'actor' => self::JANICE])->assertOk()
            ->assertJsonPath('warnings.0.count', 1);
        $this->patchJson("/api/tickets/{$t['key']}", ['priority' => 'p1', 'actor' => self::JANICE])->assertOk()->assertJsonPath('warnings.0.count', 1);
        $this->setStatus(['status' => 'waiting', 'actor' => self::JANICE])->assertOk()->assertJsonPath('warnings', []);
    }

    #[Test]
    public function project_summary_carries_the_pm_status_and_ticket_counts(): void
    {
        $this->api();
        $this->waitingTicket();
        $this->newTicket(['status' => 'in_progress']);
        $this->setStatus(['status' => 'waiting', 'note' => 'Need the copy call.', 'actor' => self::JANICE]);
        $d = $this->getJson('/api/projects/'.self::CA)->assertOk()->json('data');
        $this->assertSame(['waiting', 'Need the copy call.', self::JANICE, 'CA', 1], [$d['pmStatus'], $d['pmStatusNote'], $d['pmStatusSetBy'], $d['ticketPrefix'], $d['waitingTilenCount']]);
        $this->assertSame(['backlog' => 0, 'todo' => 0, 'in_progress' => 1, 'waiting_tilen' => 1, 'review' => 0, 'done' => 0], $d['ticketCounts']);
        $this->assertNotNull($d['pmStatusSetAt']);
        $this->assertNotNull($d['lastActivityAt']);
        // Existing keys stay (derived r1 status from Required inputs).
        $this->assertSame('waiting', $d['status']);
        $this->assertArrayHasKey('openInputs', $d);
    }
}
