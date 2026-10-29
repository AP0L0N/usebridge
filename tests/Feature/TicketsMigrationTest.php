<?php

namespace Tests\Feature;

use App\Models\NextStep;
use App\Models\Question;
use App\Models\RequiredInput;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use Database\Seeders\BridgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/** `php artisan bridge:tickets-migrate` (carry-over) and the legacy read-only switch. */
class TicketsMigrationTest extends TestCase
{
    use RefreshDatabase;
    use TicketHelpers;

    private const TOTAL = 'total: questions open 1, answered 2, inputs 2, next steps 4 → tickets 9 (waiting_tilen 5, todo 1, in_progress 3) · skipped 0';

    private string $long;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets('2026-10-20 09:00:00');
        $this->long = 'Should we '.str_repeat('really ', 30).'launch the autumn campaign on Monday?';

        // Concierge Avant (PM Janice): seeded open input in-ca-1 (waiting on Tilen) + these.
        Question::create(['id' => 'q-open', 'project_id' => self::CA, 'text' => $this->long, 'context' => 'Budget is approved.', 'type' => 'yesno', 'asked_by' => 'Janice']);
        Question::create(['id' => 'q-yes', 'project_id' => self::CA, 'text' => 'Use the new logo?', 'type' => 'yesno', 'asked_by' => 'Tommy Suarez',
            'state' => 'answered', 'answer_choice' => 'yes', 'answered_at' => '2026-10-19 18:00:00']);
        Question::create(['id' => 'q-text', 'project_id' => self::CA, 'text' => 'Which colour?', 'type' => 'text', 'asked_by' => 'Some Freelancer',
            'state' => 'answered', 'answer_text' => 'The deep blue.', 'answered_at' => '2026-10-19 19:00:00']);
        Question::create(['id' => 'q-closed', 'project_id' => self::CA, 'text' => 'Old one', 'type' => 'yesno', 'asked_by' => 'Janice', 'state' => 'closed']);
        RequiredInput::create(['id' => 'in-ca-2', 'project_id' => self::CA, 'text' => 'Final copy for the landing page', 'waiting_on' => 'Copywriter', 'since' => '2026-10-15 10:00:00']);
        RequiredInput::create(['id' => 'in-ca-3', 'project_id' => self::CA, 'text' => 'Done already', 'waiting_on' => 'Tilen', 'since' => '2026-10-01 10:00:00', 'resolved_at' => '2026-10-02 10:00:00']);
        // Merkwell (PM Anatoly): next steps.
        NextStep::create(['id' => 'ns-1', 'project_id' => self::MW, 'kind' => 'in_progress', 'text' => 'Build the price table', 'agent_id' => BridgeSeeder::AGENT_SHODAN, 'position' => 0]);
        NextStep::create(['id' => 'ns-2', 'project_id' => self::MW, 'kind' => 'blocker', 'text' => 'Client has not sent the logo files', 'owner' => 'Client', 'position' => 1]);
        NextStep::create(['id' => 'ns-3', 'project_id' => self::MW, 'kind' => 'blocker', 'text' => 'Sign the supplier contract', 'owner' => 'tilen', 'position' => 2]);
        NextStep::create(['id' => 'ns-4', 'project_id' => self::MW, 'kind' => 'in_progress', 'text' => 'Finished', 'position' => 3, 'done_at' => '2026-10-19 10:00:00']);
        NextStep::create(['id' => 'ns-5', 'project_id' => self::MW, 'kind' => 'in_progress', 'text' => 'Write the FAQ', 'position' => 4]);
        Carbon::setTestNow('2026-10-29 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function legacy(): array
    {
        return collect(['questions', 'required_inputs', 'next_steps'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])->all();
    }

    private static function without(array $tables, array $cols): array
    {
        return array_map(fn ($rows) => array_map(fn ($r) => array_diff_key($r, array_flip($cols)), $rows), $tables);
    }

    private function byRow(string $type, string $id): Ticket
    {
        return Ticket::query()->where('source_type', $type)->where('source_id', $id)->with('labels')->firstOrFail();
    }

    #[Test]
    public function dry_run_writes_nothing(): void
    {
        $before = $this->legacy();
        $this->artisan('bridge:tickets-migrate --dry-run')
            ->expectsOutputToContain('Concierge Avant ('.self::CA.'): dry run, nothing written · would be: questions open 1, answered 2, inputs 2, next steps 0 → tickets 5 (waiting_tilen 4, todo 0, in_progress 1) · skipped 0')
            ->expectsOutputToContain('total: dry run, nothing written · would be: questions open 1, answered 2, inputs 2, next steps 4 → tickets 9')
            ->assertSuccessful();
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, TicketComment::count());
        $this->assertSame(0, TicketEvent::count());
        $this->assertSame(0, DB::table('labels')->count());
        $this->assertSame([0, 0], DB::table('projects')->orderBy('id')->pluck('ticket_seq')->map(fn ($v) => (int) $v)->all());
        $this->assertSame($before, $this->legacy());
    }

    #[Test]
    public function real_run_carries_open_items_over(): void
    {
        $before = $this->legacy();
        $this->artisan('bridge:tickets-migrate')
            ->expectsOutputToContain('Merkwell ('.self::MW.'): questions open 0, answered 0, inputs 0, next steps 4 → tickets 4 (waiting_tilen 1, todo 1, in_progress 2) · skipped 0')
            ->expectsOutputToContain(self::TOTAL)
            ->assertSuccessful();
        $this->assertSame(9, Ticket::count());

        // Open yes/no question.
        $q = $this->byRow('question', 'q-open');
        $this->assertSame('waiting_tilen', $q->status);
        $this->assertSame(140, mb_strlen($q->title));
        $this->assertStringEndsWith('…', $q->title);
        $this->assertSame($this->long, $q->ask); // under 280 chars: the full text
        $this->assertStringStartsWith($this->long."\n\n_Carried over from Questions for Tilen (asked by Janice, 20 Oct 2026)._", $q->description);
        $this->assertSame(['Budget is approved.', ['Yes', 'No']], [$q->ask_context, $q->ask_options]);
        $this->assertSame(['agent', self::JANICE], [$q->assignee_type, $q->assignee_id]);
        $this->assertSame([self::JANICE, 'agent'], [$q->created_by, $q->created_by_type]); // "Janice" -> Dr. Janice Polito
        $this->assertSame('2026-10-20 09:00:00', $q->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-20 09:00:00', $q->waiting_since->format('Y-m-d H:i:s'));
        $this->assertSame(['question'], $q->labels->pluck('name')->all());
        $this->assertFalse($q->needsPmPickup());
        $this->assertStringStartsWith('CA-', $q->key);

        // Answered questions: Tilen input comment at answered_at, not acked -> PM pickup.
        $y = $this->byRow('question', 'q-yes');
        $this->assertSame('_Carried over from Questions for Tilen (asked by Tommy Suarez, 20 Oct 2026)._', $y->description);
        $this->assertSame([BridgeSeeder::AGENT_TOMMY, 'agent'], [$y->created_by, $y->created_by_type]);
        $c = TicketComment::query()->where('ticket_id', $y->id)->sole();
        $this->assertSame(['human', 'tilen', true, 'Yes', 'Yes'], [$c->author_type, $c->author_id, $c->is_tilen_input, $c->input_choice, $c->body]);
        $this->assertSame('2026-10-19 18:00:00', $c->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-19 18:00:00', $y->tilen_input_at->format('Y-m-d H:i:s'));
        $this->assertTrue($y->needsPmPickup());
        $t = $this->byRow('question', 'q-text');
        $this->assertNull($t->ask_options);
        $this->assertSame(['migration', 'system'], [$t->created_by, $t->created_by_type]);
        $tc = TicketComment::query()->where('ticket_id', $t->id)->sole();
        $this->assertSame(['The deep blue.', null], [$tc->body, $tc->input_choice]);

        // Required inputs.
        $i1 = $this->byRow('required_input', 'in-ca-1');
        $this->assertSame(['waiting_tilen', 'Approve or reject phase-2 scope freeze'], [$i1->status, $i1->ask]);
        $this->assertSame(['input'], $i1->labels->pluck('name')->all());
        $this->assertStringContainsString('_Carried over from Required inputs (waiting on Tilen, since 8 Sep 2026)._', $i1->description);
        $this->assertSame('2026-09-08 15:00:00', $i1->waiting_since->format('Y-m-d H:i:s'));
        $this->assertSame(['input', 'waiting-on-other'], $this->byRow('required_input', 'in-ca-2')->labels->pluck('name')->all());
        $i2 = $this->byRow('required_input', 'in-ca-2'); // not Tilen's: In progress, assigned to that person, no ask
        $this->assertSame(['in_progress', 'human', 'Copywriter', null, null], [$i2->status, $i2->assignee_type, $i2->assignee_id, $i2->ask, $i2->waiting_since]);

        // Next steps.
        $n1 = $this->byRow('next_step', 'ns-1');
        $this->assertSame(['in_progress', 'agent', BridgeSeeder::AGENT_SHODAN, ['next-step']], [$n1->status, $n1->assignee_type, $n1->assignee_id, $n1->labels->pluck('name')->all()]);
        $n2 = $this->byRow('next_step', 'ns-2');
        $this->assertSame(['todo', 'Client has not sent the logo files', 'human', 'Client'], [$n2->status, $n2->blocked_reason, $n2->assignee_type, $n2->assignee_id]);
        $this->assertSame(['blocker', 'next-step'], $n2->labels->pluck('name')->all());
        $n3 = $this->byRow('next_step', 'ns-3');
        $this->assertSame(['waiting_tilen', 'Sign the supplier contract', null], [$n3->status, $n3->ask, $n3->blocked_reason]);
        $n5 = $this->byRow('next_step', 'ns-5');
        $this->assertSame(['agent', self::ANATOLY], [$n5->assignee_type, $n5->assignee_id]); // falls back to the PM
        $this->assertSame([0, 1], [$n1->position, $n5->position]);
        $this->assertSame(['MW-1', 'MW-2', 'MW-3', 'MW-4'], Ticket::query()->where('project_id', self::MW)->orderBy('key')->pluck('key')->all());

        // Events: created by migration via api.
        $this->assertSame(9, TicketEvent::query()->where('type', 'created')->where('actor_id', 'migration')->where('actor_type', 'system')->where('source', 'api')->count());

        // Legacy rows: only superseded_at + ticket_id changed; nothing deleted; closed/resolved/done untouched.
        $after = $this->legacy();
        $this->assertSame(self::without($before, ['superseded_at', 'ticket_id']), self::without($after, ['superseded_at', 'ticket_id']));
        $this->assertSame($q->id, Question::find('q-open')->ticket_id);
        $this->assertNotNull(Question::find('q-open')->superseded_at);
        $this->assertNull(Question::find('q-closed')->ticket_id);
        $this->assertNull(RequiredInput::find('in-ca-3')->ticket_id);
        $this->assertNull(NextStep::find('ns-4')->superseded_at);
        $this->assertSame('open', Question::find('q-open')->state);
    }

    #[Test]
    public function second_run_is_a_no_op_and_new_rows_are_picked_up(): void
    {
        $this->artisan('bridge:tickets-migrate')->assertSuccessful();
        $this->artisan('bridge:tickets-migrate')
            ->expectsOutputToContain('total: questions open 0, answered 0, inputs 0, next steps 0 → tickets 0 (waiting_tilen 0, todo 0, in_progress 0) · skipped 9')
            ->assertSuccessful();
        $this->assertSame(9, Ticket::count());

        // Even with the legacy flags cleared, the (source_type, source_id) ticket blocks a duplicate.
        Question::query()->whereKey('q-open')->toBase()->update(['ticket_id' => null, 'superseded_at' => null]);
        Question::create(['id' => 'q-new', 'project_id' => self::MW, 'text' => 'New question?', 'type' => 'yesno', 'asked_by' => 'Anatoly']);
        $this->artisan('bridge:tickets-migrate --project='.self::MW)
            ->expectsOutputToContain('total: questions open 1, answered 0, inputs 0, next steps 0 → tickets 1 (waiting_tilen 1, todo 0, in_progress 0) · skipped 4')
            ->assertSuccessful();
        $this->artisan('bridge:tickets-migrate')->expectsOutputToContain('· skipped 10')->assertSuccessful();
        $this->assertSame(10, Ticket::count());
        $this->assertSame('MW-5', $this->byRow('question', 'q-new')->key);
        $this->assertSame([self::ANATOLY, 'agent'], [$this->byRow('question', 'q-new')->created_by, $this->byRow('question', 'q-new')->created_by_type]);

        $this->artisan('bridge:tickets-migrate --project=proj-nope')->expectsOutputToContain('Unknown project id')->assertFailed();
    }

    #[Test]
    public function carried_tickets_work_with_the_api(): void
    {
        $this->artisan('bridge:tickets-migrate')->assertSuccessful();
        $this->api();
        $keys = array_column($this->getJson('/api/tickets?needs_pm_pickup=1')->assertOk()->json('data'), 'key');
        $this->assertCount(2, $keys);
        $this->getJson('/api/tickets?label=question&project_id='.self::CA)->assertJsonCount(3, 'data');
        $this->getJson('/api/ticket-comments/feed?inputs_only=1')->assertJsonCount(2, 'data');
        $this->postJson("/api/tickets/{$keys[0]}/ack-input", ['actor' => self::JANICE])->assertOk();
    }

    #[Test]
    public function legacy_read_only_switch(): void
    {
        $this->api();
        config(['bridge.legacy_read_only' => true]);
        foreach ([
            ['post', '/api/questions', ['project_id' => self::CA, 'text' => 'x', 'type' => 'yesno', 'asked_by' => 'x']],
            ['patch', '/api/questions/q-open', ['state' => 'closed']],
            ['delete', '/api/questions/q-open', []],
            ['post', '/api/projects/'.self::CA.'/questions', []],
            ['post', '/api/inputs', []],
            ['patch', '/api/inputs/in-ca-1', []],
            ['delete', '/api/inputs/in-ca-1', []],
            ['post', '/api/next-steps', []],
            ['patch', '/api/next-steps/ns-1', []],
            ['delete', '/api/next-steps/ns-1', []],
            ['post', '/api/ceo-queue-items', []],
        ] as [$method, $uri, $body]) {
            $this->json($method, $uri, $body)->assertStatus(410)->assertExactJson(['message' => 'Superseded by tickets; see docs/API-TICKETS.md']);
        }
        $this->gate()->post('/projects/concierge-avant/inputs/in-ca-1/resolve', ['resolved' => true])->assertStatus(410);
        $this->post('/projects/concierge-avant/questions/q-open/answer', ['answer_choice' => 'yes'])->assertStatus(410);
        $this->assertSame('open', Question::find('q-open')->state);
        $this->assertNull(RequiredInput::find('in-ca-1')->resolved_at);
        $this->assertNotNull(NextStep::find('ns-1'));

        // Reads keep working, and so does the project page.
        $this->getJson('/api/questions')->assertOk();
        $this->getJson('/api/inputs')->assertOk();
        $this->getJson('/api/next-steps')->assertOk();
        $this->getJson('/api/ceo-queue-items')->assertOk();
        $this->get('/projects/concierge-avant')->assertOk();

        // Off (default): legacy writes work as before.
        config(['bridge.legacy_read_only' => false]);
        $this->patchJson('/api/questions/q-open', ['state' => 'closed'])->assertOk();
    }

    public function test_next_step_owner_name_matching_an_agent_becomes_an_agent_assignee(): void
    {
        NextStep::create(['id' => 'ns-owner', 'project_id' => self::MW, 'kind' => 'in_progress', 'text' => 'Deploy the hotfix', 'owner' => 'shodan', 'position' => 9]);

        $this->artisan('bridge:tickets-migrate', ['--project' => self::MW])->assertSuccessful();

        $t = $this->byRow('next_step', 'ns-owner');
        $this->assertSame(['agent', BridgeSeeder::AGENT_SHODAN], [$t->assignee_type, $t->assignee_id]);
    }

    public function test_input_waiting_on_an_agent_goes_to_in_progress_with_that_agent(): void
    {
        RequiredInput::create(['id' => 'in-janice', 'project_id' => self::CA, 'text' => 'Janice confirms the guest copy', 'waiting_on' => 'Janice', 'since' => '2026-10-20 10:00:00']);

        $this->artisan('bridge:tickets-migrate', ['--project' => self::CA])->assertSuccessful();

        $t = $this->byRow('required_input', 'in-janice');
        $this->assertSame(['in_progress', 'agent', self::JANICE], [$t->status, $t->assignee_type, $t->assignee_id]);
        $this->assertSame(['input', 'waiting-on-other'], $t->labels->pluck('name')->all());
    }
}
