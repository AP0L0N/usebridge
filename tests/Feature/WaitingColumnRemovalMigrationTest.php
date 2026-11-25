<?php

namespace Tests\Feature;

use App\Models\TicketEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/**
 * Migration 2026_11_25_100100: every ticket still stored in the removed waiting_tilen column moves to the column it
 * came from (else In progress) and is flagged waiting on input. Counted per project, idempotent, reversible, no ticket
 * lost. The old state is written as raw rows, as it sits in a database from before the migration.
 */
class WaitingColumnRemovalMigrationTest extends TestCase
{
    use RefreshDatabase;
    use TicketHelpers;

    private const WAITING = 'waiting_tilen';

    private BufferedOutput $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
        $this->api();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function migration(): object
    {
        $migration = require database_path('migrations/2026_11_25_100100_remove_waiting_tilen_column.php');
        $migration->output = $this->out = new BufferedOutput;

        return $migration;
    }

    /** The lines the migration printed (what `artisan migrate` shows), without the blank lead line and the indent. */
    private function printed(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $this->out->fetch()))));
    }

    /** A `moved` row as the old code wrote it. */
    private function moved(string $ticketId, string $from, string $to): void
    {
        $t = DB::table('tickets')->where('id', $ticketId)->first();
        DB::table('ticket_events')->insert([
            'ticket_id' => $t->id, 'project_id' => $t->project_id, 'workstream_id' => $t->workstream_id, 'workspace_id' => $t->workspace_id,
            'type' => 'moved', 'from' => json_encode(['status' => $from, 'position' => 0]), 'to' => json_encode(['status' => $to, 'position' => 0]),
            'actor_id' => self::JANICE, 'actor_type' => 'agent', 'source' => 'api', 'created_at' => Carbon::now(),
        ]);
    }

    /**
     * A ticket as it sat in the waiting column: created in $path[0], then moved along $path into waiting_tilen
     * ($path = [] : created in the waiting column). @return string ticket id
     */
    private function waiting(string $title, array $path, ?string $since = '2026-10-20 09:00:00', int $position = 0, string $project = self::BH, array $data = []): string
    {
        $t = $this->api()->newTicket(['title' => $title, 'status' => $path[0] ?? 'todo', 'ask' => "Ask of $title?", ...$data], $project);
        foreach ([...$path, self::WAITING] as $i => $to) {
            if ($i > 0) {
                $this->moved($t['id'], $path[$i - 1], $to);
            }
        }
        if ($path === []) {
            DB::table('ticket_events')->where('ticket_id', $t['id'])->where('type', 'created')
                ->update(['to' => json_encode(['status' => self::WAITING, 'title' => $title, 'key' => $t['key']])]);
        }
        DB::table('tickets')->where('id', $t['id'])->update(['status' => self::WAITING, 'position' => $position, 'waiting_since' => $since, 'done_at' => null]);

        return $t['id'];
    }

    private function row(string $id): object
    {
        return DB::table('tickets')->where('id', $id)->first();
    }

    /** Every tickets row (and every event), to prove what did not change. */
    private function snapshot(): array
    {
        return [DB::table('tickets')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), DB::table('ticket_events')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()];
    }

    #[Test]
    public function tickets_go_back_to_the_column_they_came_from_else_in_progress_and_are_flagged(): void
    {
        $busy = $this->newTicket(['title' => 'Already in progress', 'status' => 'in_progress']);
        $review = $this->newTicket(['title' => 'Already in review', 'status' => 'review']);
        $fromProgress = $this->waiting('From in progress', ['todo', 'in_progress'], '2026-10-21 08:00:00');
        $fromReview = $this->waiting('From review', ['review'], '2026-10-22 08:00:00', 1);
        $this->moved($fromReview, self::WAITING, self::WAITING); // reordered inside the waiting column: still came from review
        $backAgain = $this->waiting('Out and back in', ['backlog', self::WAITING, 'review'], '2026-10-23 08:00:00', 2);
        $createdWaiting = $this->waiting('Created waiting', [], '2026-10-20 08:00:00', 3);
        $noSince = $this->waiting('No history', ['todo'], null, 4);
        DB::table('ticket_events')->where('ticket_id', $noSince)->delete();
        $fromDone = $this->waiting('From done', ['done'], '2026-10-24 08:00:00', 5);
        $untouched = DB::table('tickets')->whereIn('id', [$busy['id'], $review['id']])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->travelMinutes(30);
        $now = Carbon::now()->format('Y-m-d H:i:s');
        $this->migration()->up();

        $this->assertSame(0, DB::table('tickets')->where('status', self::WAITING)->count());
        $this->assertSame(8, DB::table('tickets')->count());
        $state = fn (string $id) => [$this->row($id)->status, $this->row($id)->position, $this->row($id)->waiting_on_input_at, $this->row($id)->waiting_since];
        // The newest move INTO the waiting column names the column to go back to; end of that column.
        $this->assertSame(['in_progress', 2, '2026-10-21 08:00:00', null], $state($fromProgress));
        $this->assertSame(['review', 1, '2026-10-22 08:00:00', null], $state($fromReview));
        $this->assertSame(['review', 2, '2026-10-23 08:00:00', null], $state($backAgain));
        // Created in the waiting column, or no history: In progress (oldest waiting first). No waiting_since: flagged now.
        $this->assertSame(['in_progress', 1, '2026-10-20 08:00:00', null], $state($createdWaiting));
        $this->assertSame(['in_progress', 4, $now, null], $state($noSince));
        // From Done: not finished while it needs input, so In progress, and not marked done.
        $this->assertSame(['in_progress', 3, '2026-10-24 08:00:00', null, null], [...$state($fromDone), $this->row($fromDone)->done_at]);
        // Tickets that were not in the waiting column are untouched.
        $this->assertSame($untouched, DB::table('tickets')->whereIn('id', [$busy['id'], $review['id']])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame([0, 1, 2, 3, 4], DB::table('tickets')->where('status', 'in_progress')->orderBy('position')->pluck('position')->all());

        // History: flagged, then moved, both by `migration`, marked so down() can find them.
        $events = TicketEvent::query()->where('ticket_id', $fromReview)->orderByDesc('id')->limit(2)->get()->reverse()->values();
        $this->assertSame(['waiting_set', 'moved'], $events->pluck('type')->all());
        $this->assertSame([['migration', 'system', 'api'], ['migration', 'system', 'api']], $events->map(fn ($e) => [$e->actor_id, $e->actor_type, $e->source])->all());
        $this->assertSame(['waiting_on_input_at' => '2026-10-22 08:00:00', 'reason' => 'waiting_column_removed'], $events[0]->to);
        $this->assertSame([['status' => self::WAITING, 'position' => 1, 'waiting_since' => '2026-10-22 08:00:00'], ['status' => 'review', 'position' => 1, 'reason' => 'waiting_column_removed']], [$events[1]->from, $events[1]->to]);

        // The API reads them as flagged tickets in their column; the old column's name still labels the history line.
        $t = $this->api()->getJson('/api/tickets/'.$this->row($fromReview)->key)->assertOk()->json('data');
        $this->assertSame(['review', 'Review / QA', true, true, 'Ask of From review?'], [$t['status'], $t['status_label'], $t['waiting_on_input'], $t['waiting_on_input_outstanding'], $t['ask']]);
        $this->assertSame(['status' => self::WAITING, 'position' => 1, 'waiting_since' => '2026-10-22 08:00:00', 'status_label' => 'Waiting on Tilen'], $t['events'][0]['from']);
        $this->assertSame(6, count($this->getJson('/api/tickets?waiting_on_input=1')->json('data')));
    }

    #[Test]
    public function it_reports_the_count_per_board_to_the_console_and_the_log_and_a_second_run_moves_nothing(): void
    {
        $this->waiting('BH one', ['todo']);
        $this->waiting('BH two', ['review'], '2026-10-21 09:00:00', 1);
        $archived = $this->waiting('BH archived', ['todo'], '2026-10-19 09:00:00', 7);
        DB::table('tickets')->where('id', $archived)->update(['archived_at' => '2026-10-25 09:00:00']);
        $this->waiting('NW one', ['in_progress'], '2026-10-22 09:00:00', 0, self::NW);
        $this->newTicket(['title' => 'Not waiting']);
        $total = DB::table('tickets')->count();
        Log::spy();

        $this->migration()->up();

        $lines = [
            'Blue Harbor ('.self::BH.'): 3 tickets moved',
            'Northwind ('.self::NW.'): 1 ticket moved',
            'Waiting column removal: 4 tickets moved in total, on 2 boards',
        ];
        $this->assertSame($lines, $this->printed());
        foreach ($lines as $line) {
            Log::shouldHaveReceived('info')->with($line)->once();
        }

        // No ticket lost: the archived one moved too (still archived, its position kept), and it is flagged.
        $this->assertSame($total, DB::table('tickets')->count());
        $this->assertSame(0, DB::table('tickets')->where('status', self::WAITING)->count());
        $a = $this->row($archived);
        $this->assertSame(['todo', 7, '2026-10-25 09:00:00', '2026-10-19 09:00:00'], [$a->status, $a->position, $a->archived_at, $a->waiting_on_input_at]);

        // Second run: nothing moves twice, nothing is printed, the log says zero.
        $before = $this->snapshot();
        $this->travelMinutes(10);
        $this->migration()->up();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->printed());
        Log::shouldHaveReceived('info')->with('Waiting column removal: 0 tickets moved in total, on 0 boards')->once();
    }

    #[Test]
    public function the_ask_the_answer_the_pickup_state_and_the_workstream_survive_the_move(): void
    {
        $answered = $this->waiting('Answered', [], '2026-10-29 10:00:00', 0, self::BH, ['ask_options' => ['Yes', 'No'], 'workstream' => 'Mobile']);
        $open = $this->waiting('Open', ['todo'], '2026-10-29 10:00:00', 1);
        $key = $this->row($answered)->key;
        $workstream = $this->row($answered)->workstream_id;
        $askedAt = Carbon::now()->toIso8601String();
        $this->travelMinutes(5);
        $this->tilenSays($key, ['input_choice' => 'Yes'])->assertCreated();
        $inputAt = $this->row($answered)->tilen_input_at;

        $this->travelMinutes(60);
        $this->migration()->up();

        $row = $this->row($answered);
        $this->assertSame(['in_progress', $workstream, 'Ask of Answered?', $inputAt, null], [$row->status, $row->workstream_id, $row->ask, $row->tilen_input_at, $row->tilen_input_ack_at]);
        $t = $this->api()->getJson("/api/tickets/$key")->json('data');
        // His answer came after the ticket started waiting: still awaiting pickup, no highlight, and the question keeps
        // its round (asked when the ticket was created waiting, answered, not a new blank question).
        $this->assertSame([true, true, false, true, 'Mobile'], [$t['waiting_on_input'], $t['needs_pm_pickup'], $t['waiting_on_input_outstanding'], $t['owner_answered_ask'], $t['workstream']]);
        $questions = $this->getJson('/api/projects/'.self::BH.'/questions')->assertOk()->json('data');
        $this->assertSame([[$key, 'Yes', $askedAt]], array_map(fn ($q) => [$q['ticket_key'], $q['answer_choice'], $q['asked_at']], $questions['answered']));

        // The unanswered one is an open question and highlighted.
        $o = $this->getJson('/api/tickets/'.$this->row($open)->key)->json('data');
        $this->assertSame(['todo', true, true, false], [$o['status'], $o['waiting_on_input'], $o['waiting_on_input_outstanding'], $o['needs_pm_pickup']]);
        $this->assertEqualsCanonicalizing(['Answered', 'Open'], array_column($this->getJson('/api/projects/'.self::BH.'/asks')->json('data'), 'title'));
        $this->assertSame(1, $this->getJson('/api/projects/'.self::BH.'/board')->json('data.project.open_questions_count'));
    }

    #[Test]
    public function down_puts_the_moved_tickets_back_and_leaves_every_other_ticket_alone(): void
    {
        $a = $this->waiting('From review', ['review'], '2026-10-22 08:00:00', 0);
        $b = $this->waiting('No since', [], null, 1);
        $flaggedLater = $this->newTicket(['title' => 'Flagged by an agent', 'status' => 'in_progress']);
        $events = fn () => DB::table('ticket_events')->whereIn('ticket_id', [$a, $b])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $before = $events();

        $this->migration()->up();
        // After the migration an agent flags another ticket: down() must not touch it.
        $this->travelMinutes(5);
        $this->patchJson("/api/tickets/{$flaggedLater['key']}", ['waiting_on_input' => true, 'actor' => self::JANICE])->assertOk();
        $flagged = (array) $this->row($flaggedLater['id']);

        $this->migration()->down();

        $this->assertSame([self::WAITING, 0, '2026-10-22 08:00:00', null], [$this->row($a)->status, $this->row($a)->position, $this->row($a)->waiting_since, $this->row($a)->waiting_on_input_at]);
        $this->assertSame([self::WAITING, 1, null, null], [$this->row($b)->status, $this->row($b)->position, $this->row($b)->waiting_since, $this->row($b)->waiting_on_input_at]);
        $this->assertSame($flagged, (array) $this->row($flaggedLater['id']));
        $this->assertSame(['Blue Harbor ('.self::BH.'): 2 tickets moved back', 'Waiting column removal: 2 tickets moved back in total, on 1 board'], $this->printed());
        // The migration's own events are gone; the rest of the history is as it was.
        $this->assertSame(0, DB::table('ticket_events')->where('actor_id', 'migration')->count());
        $this->assertSame($before, $events());

        // Up again after a down works like the first time.
        $this->migration()->up();
        $this->assertSame(['review', 'in_progress'], [$this->row($a)->status, $this->row($b)->status]);
        $this->assertSame(['Blue Harbor ('.self::BH.'): 2 tickets moved', 'Waiting column removal: 2 tickets moved in total, on 1 board'], $this->printed());
    }

    #[Test]
    public function the_schema_migration_adds_and_drops_only_the_flag_column(): void
    {
        $schema = require database_path('migrations/2026_11_25_100000_ticket_waiting_on_input.php');
        $columns = Schema::getColumnListing('tickets');
        $this->assertContains('waiting_on_input_at', $columns);
        $this->assertContains('waiting_since', $columns); // the old column stays (no longer read or written)

        $schema->up(); // idempotent
        $this->assertSame($columns, Schema::getColumnListing('tickets'));
        $schema->down();
        $this->assertSame(array_values(array_diff($columns, ['waiting_on_input_at'])), Schema::getColumnListing('tickets'));
        $schema->down();
        $schema->up();
        $this->assertEqualsCanonicalizing($columns, Schema::getColumnListing('tickets'));
    }
}
