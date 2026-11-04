<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\AccountService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\McpHelpers;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/**
 * r4 Questions panel (design/r4/SPEC.md "Assumed data"): the question payload derived from tickets (no questions
 * table), its states, the panel routes (answer / dismiss / restore), the open counts, the API and MCP views. Synthetic data.
 */
class BridgeR4QuestionsPanelTest extends TestCase
{
    use McpHelpers, RefreshDatabase, TicketHelpers;

    private const FIELDS = [
        'id', 'ticket_id', 'ticket_key', 'project_id', 'text', 'context', 'options', 'allow_text', 'asked_by', 'asked_at',
        'state', 'answer_choice', 'answer_text', 'answered_at', 'picked_up_at', 'closed_at', 'dismissed_at',
    ];

    /** Per-agent token: acts as Janice (PM of Blue Harbor). */
    private string $janice;

    /** Shared write token: `actor` names the agent. */
    private string $shared;

    private string $read;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
        Notification::fake();
        $ws = app(CurrentWorkspace::class)->default();
        $this->janice = $this->scopedToken($ws->owner, $ws, 'write', self::JANICE);
        $this->shared = $this->scopedToken($ws->owner, $ws, 'write', 'grok-laptop');
        $this->read = $this->scopedToken($ws->owner, $ws, 'read', 'reader');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function create(array $data, string $project = self::BH, ?string $token = null): string
    {
        return $this->bearer($token ?? $this->janice, 'POST', "/api/projects/$project/tickets", ['title' => 'Draft the brief', ...$data])->assertCreated()->json('data.key');
    }

    private function ask(array $data = [], string $project = self::BH): string
    {
        return $this->create(['status' => 'waiting_tilen', 'ask' => 'Approve staging?', 'ask_context' => 'QA passed.', 'ask_options' => ['Approve', 'Hold'], ...$data], $project);
    }

    private function qid(string $key): string
    {
        return 'q-'.Ticket::query()->where('key', $key)->value('id');
    }

    /** GET /api/projects/{id}/questions. */
    private function panel(string $project = self::BH, string $state = 'all'): array
    {
        return $this->bearer($this->read, 'GET', "/api/projects/$project/questions?state=$state")->assertOk()->json('data');
    }

    /** The question of $key wherever it is in the panel. */
    private function q(string $key, string $project = self::BH): array
    {
        $p = $this->panel($project);
        $all = collect([...$p['open'], ...$p['answered'], ...$p['closed']])->keyBy('ticket_key');
        $this->assertArrayHasKey($key, $all->all(), "$key is not in the panel");

        return $all[$key];
    }

    private function panelPost(string $action, string $key, array $data = [], string $slug = 'blue-harbor')
    {
        $this->app['auth']->forgetGuards();

        return $this->asOwner()->postJson("/projects/$slug/questions/{$this->qid($key)}/$action", $data);
    }

    private function counts(): array
    {
        $this->app['auth']->forgetGuards();
        $cards = null;
        $this->asOwner()->get('/')->assertOk()->assertInertia(function (Assert $p) use (&$cards) {
            $cards = $p->toArray()['props']['projects'];
        });
        $api = collect($this->bearer($this->read, 'GET', '/api/projects')->json('data'))->keyBy('id');

        return [
            'portfolio' => array_column($cards, 'openQuestions', 'id')[self::BH],
            'api' => $api[self::BH]['open_questions_count'],
            'board' => $this->bearer($this->read, 'GET', '/api/projects/'.self::BH.'/board')->json('data.project.open_questions_count'),
            'asks' => $api[self::BH]['open_asks_count'],
        ];
    }

    /** A second account with its own project and question. @return array{0: User, 1: string token, 2: string key} */
    private function otherWorkspace(): array
    {
        $marta = app(AccountService::class)->register('Marta', 'marta@example.test', 'correct horse battery', 'Marta Labs');
        $marta->markEmailAsVerified();
        $ws = Workspace::query()->where('owner_user_id', $marta->id)->firstOrFail();
        app(CurrentWorkspace::class)->runAs($ws, fn () => Agent::query()->create(['id' => 'agent-marta', 'name' => 'Marta Bot', 'role_label' => 'PM']));
        $seed = $marta->createToken('seed', ['*'])->plainTextToken;
        $this->bearer($seed, 'POST', '/api/projects', ['id' => 'proj-marta', 'name' => 'Marta Lab', 'slug' => 'marta-lab', 'owner_agent_id' => 'agent-marta'])->assertCreated();
        $token = $this->scopedToken($marta, $ws, 'write', 'agent-marta');
        $key = $this->create(['title' => 'Marta secret', 'status' => 'waiting_tilen', 'ask' => 'Marta secret ask?'], 'proj-marta', $token);

        return [$marta, $token, $key];
    }

    // ---------------------------------------------------------------- shape

    #[Test]
    public function the_payload_has_exactly_the_design_fields(): void
    {
        $a = $this->ask();
        $askedAt = Carbon::now()->toIso8601String();
        $this->travelMinutes(10);
        // Moved in by Captain (CEO) as a free-text question.
        $b = $this->create(['title' => 'Pick the venue', 'status' => 'todo']);
        $this->bearer($this->shared, 'POST', "/api/tickets/$b/move", ['status' => 'waiting_tilen', 'ask' => 'Harbor hall or the loft?', 'actor' => self::CAPTAIN])->assertOk();
        // An ask on a ticket that never waited on the owner is not a question.
        $this->create(['title' => 'Parked', 'status' => 'in_progress', 'ask' => 'Later?']);

        $panel = $this->panel();
        $this->assertSame(['open', 'answered', 'closed', 'has_any'], array_keys($panel));
        $this->assertSame([[$a, $b], [], [], true], [array_column($panel['open'], 'ticket_key'), $panel['answered'], $panel['closed'], $panel['has_any']]);

        $ticket = Ticket::query()->where('key', $a)->firstOrFail();
        $this->assertSame([
            'id' => 'q-'.$ticket->id,
            'ticket_id' => $ticket->id,
            'ticket_key' => $a,
            'project_id' => self::BH,
            'text' => 'Approve staging?',
            'context' => 'QA passed.',
            'options' => ['Approve', 'Hold'],
            'allow_text' => true,
            'asked_by' => ['id' => self::JANICE, 'name' => 'Dr. Janice Polito', 'role' => 'PM', 'type' => 'agent', 'source' => 'api'],
            'asked_at' => $askedAt,
            'state' => 'open',
            'answer_choice' => null,
            'answer_text' => null,
            'answered_at' => null,
            'picked_up_at' => null,
            'closed_at' => null,
            'dismissed_at' => null,
        ], $panel['open'][0]);
        $this->assertSame(self::FIELDS, array_keys($panel['open'][1]));
        $this->assertSame([[], null, ['id' => self::CAPTAIN, 'name' => 'Captain William Diego', 'role' => 'CEO', 'type' => 'agent', 'source' => 'api']],
            [$panel['open'][1]['options'], $panel['open'][1]['context'], $panel['open'][1]['asked_by']]);

        // The owner moving a ticket into the waiting column from the board asks too (person, via Bridge).
        $c = $this->create(['title' => 'Self note']);
        $this->asOwner()->postJson("/projects/blue-harbor/tickets/$c/move", ['status' => 'waiting_tilen', 'ask' => 'Remind me?'])->assertOk();
        $this->assertSame(['id' => 'tilen', 'name' => 'Tilen', 'role' => 'Owner', 'type' => 'human', 'source' => 'ui'], $this->q($c)['asked_by']);

        // The Board prop is the same payload; the API filter keeps the shape; a project without questions is empty.
        $this->app['auth']->forgetGuards();
        $expected = $this->panel();
        $this->app['auth']->forgetGuards();
        $this->asOwner()->get('/projects/blue-harbor')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Board')->where('questions', $expected)->where('board.project.open_questions_count', 3));
        $this->assertSame(['open' => [], 'answered' => [], 'closed' => [], 'has_any' => true], $this->panel(self::BH, 'answered'));
        $this->assertSame(['open' => [], 'answered' => [], 'closed' => [], 'has_any' => false], $this->panel(self::NW));
        $this->bearer($this->read, 'GET', '/api/projects/'.self::BH.'/questions?state=maybe')->assertUnprocessable()->assertJsonValidationErrors('state');
        // The lean asks list is unchanged.
        $this->assertSame([$a, $b, $c], array_column($this->bearer($this->read, 'GET', '/api/projects/'.self::BH.'/asks')->json('data'), 'key'));
    }

    // ---------------------------------------------------------------- states

    #[Test]
    public function every_state_is_derived_from_the_ticket_its_events_and_input_comments(): void
    {
        $a = $this->ask();
        $this->travelMinutes(5);

        // open -> answered: owner input, not picked up yet.
        $this->panelPost('answer', $a, ['choice' => 'Approve', 'text' => 'Ship it.'])->assertCreated();
        $answeredAt = Carbon::now()->toIso8601String();
        $q = $this->q($a);
        $this->assertSame(['answered', 'Approve', 'Ship it.', $answeredAt, null, null], [$q['state'], $q['answer_choice'], $q['answer_text'], $q['answered_at'], $q['picked_up_at'], $q['closed_at']]);
        $this->assertSame([$a], array_column($this->panel()['answered'], 'ticket_key'));

        // answered -> closed: the PM acks; the ticket stays in the waiting column.
        $this->travelMinutes(5);
        $this->bearer($this->janice, 'POST', "/api/tickets/$a/ack-input")->assertOk();
        $ackAt = Carbon::now()->toIso8601String();
        $q = $this->q($a);
        $this->assertSame(['closed', $ackAt, $ackAt, 'Approve'], [$q['state'], $q['picked_up_at'], $q['closed_at'], $q['answer_choice']]);
        $this->assertSame('waiting_tilen', Ticket::query()->where('key', $a)->value('status'));

        // A new ask (ask_set) is a new question: open again, no answer.
        $this->travelMinutes(5);
        $this->bearer($this->janice, 'PATCH', "/api/tickets/$a", ['ask' => 'Approve staging for Friday?', 'ask_options' => null])->assertOk();
        $q = $this->q($a);
        $this->assertSame(['open', 'Approve staging for Friday?', [], Carbon::now()->toIso8601String(), null, null], [$q['state'], $q['text'], $q['options'], $q['asked_at'], $q['answer_text'], $q['picked_up_at']]);

        // Free-text answer, then the PM moves the ticket on: the move acks it (picked up), closed.
        $this->travelMinutes(5);
        $this->panelPost('answer', $a, ['text' => 'Yes, Friday.'])->assertCreated();
        $this->assertSame([null, 'Yes, Friday.'], [$this->q($a)['answer_choice'], $this->q($a)['answer_text']]);
        $this->travelMinutes(5);
        $this->bearer($this->janice, 'POST', "/api/tickets/$a/move", ['status' => 'in_progress'])->assertOk();
        $q = $this->q($a);
        $this->assertSame(['closed', Carbon::now()->toIso8601String(), Carbon::now()->toIso8601String(), 'Yes, Friday.'], [$q['state'], $q['picked_up_at'], $q['closed_at'], $q['answer_text']]);

        // The ask cleared after the ticket moved on: the closed question keeps its last text.
        $this->bearer($this->janice, 'PATCH', "/api/tickets/$a", ['ask' => null])->assertOk();
        $this->assertSame(['closed', 'Approve staging for Friday?'], [$this->q($a)['state'], $this->q($a)['text']]);

        // Back into the waiting column: a new question, open.
        $this->travelMinutes(5);
        $this->bearer($this->janice, 'POST', "/api/tickets/$a/move", ['status' => 'waiting_tilen', 'ask' => 'One more check?'])->assertOk();
        $this->assertSame(['open', 'One more check?', null], [$this->q($a)['state'], $this->q($a)['text'], $this->q($a)['answered_at']]);

        // Moved on without an answer: closed, nothing picked up.
        $b = $this->ask(['title' => 'Venue']);
        $this->travelMinutes(5);
        $this->bearer($this->janice, 'POST', "/api/tickets/$b/move", ['status' => 'todo'])->assertOk();
        $q = $this->q($b);
        $this->assertSame(['closed', null, null, Carbon::now()->toIso8601String()], [$q['state'], $q['answered_at'], $q['picked_up_at'], $q['closed_at']]);

        // Archived while waiting (answered, never picked up): closed at the archive.
        $c = $this->ask(['title' => 'Old ask', 'ask_options' => ['Yes', 'No']]);
        $this->panelPost('answer', $c, ['choice' => 'No'])->assertCreated();
        $this->assertSame(['No', null], [$this->q($c)['answer_choice'], $this->q($c)['answer_text']]); // choice only: no text
        $this->travelMinutes(5);
        $this->bearer($this->janice, 'POST', "/api/tickets/$c/archive")->assertOk();
        $q = $this->q($c);
        $this->assertSame(['closed', null, Carbon::now()->toIso8601String()], [$q['state'], $q['picked_up_at'], $q['closed_at']]);

        // A retracted input is no answer.
        $d = $this->ask(['title' => 'Retract']);
        $this->panelPost('answer', $d, ['choice' => 'Hold'])->assertCreated();
        TicketComment::query()->where('ticket_id', Ticket::query()->where('key', $d)->value('id'))->update(['retracted_at' => now()]);
        $this->assertSame('open', $this->q($d)['state']);

        // Filters: closed and newest first.
        $this->assertSame([$c, $b], array_column($this->panel(self::BH, 'closed')['closed'], 'ticket_key'));
        $this->assertSame([$a, $d], array_column($this->panel(self::BH, 'open')['open'], 'ticket_key'));
    }

    #[Test]
    public function the_closed_group_is_newest_first_and_capped(): void
    {
        $keys = [];
        for ($i = 0; $i < 22; $i++) {
            $keys[] = $k = $this->ask(['title' => "Ask $i"]);
            $this->travelMinutes(1);
            $this->bearer($this->janice, 'POST', "/api/tickets/$k/move", ['status' => 'todo'])->assertOk();
        }
        $closed = $this->panel()['closed'];
        $this->assertCount(20, $closed);
        $this->assertSame(array_slice(array_reverse($keys), 0, 20), array_column($closed, 'ticket_key'));
    }

    // ---------------------------------------------------------------- answer

    #[Test]
    public function answering_has_the_same_side_effects_as_the_drawer_input(): void
    {
        $a = $this->ask();
        $b = $this->ask(['title' => 'Drawer twin']);
        $this->travelMinutes(5);

        $res = $this->panelPost('answer', $a, ['choice' => 'Approve', 'text' => 'Ship it.'])->assertCreated();
        $this->assertSame(self::FIELDS, array_keys($res->json('data')));
        $res->assertJsonPath('data.state', 'answered')->assertJsonPath('comment.is_owner_input', true)
            ->assertJsonPath('comment.input_choice', 'Approve')->assertJsonPath('comment.body', 'Ship it.')
            ->assertJsonPath('comment.author_id', 'tilen')->assertJsonPath('comment.source', 'ui');
        // The drawer's "Your input" on the twin ticket.
        $this->app['auth']->forgetGuards();
        $this->tilenSays($b, ['input_choice' => 'Approve', 'body' => 'Ship it.', 'is_owner_input' => true])->assertCreated();

        $pick = fn (string $key) => [
            Ticket::query()->where('key', $key)->firstOrFail()->needsPmPickup(),
            TicketComment::query()->where('ticket_id', Ticket::query()->where('key', $key)->value('id'))->get(['author_type', 'author_id', 'body', 'is_tilen_input', 'input_choice'])->toArray(),
            TicketEvent::query()->where('ticket_id', Ticket::query()->where('key', $key)->value('id'))->where('type', 'commented')->get()
                ->map(fn ($e) => [$e->actor_id, $e->actor_type, $e->source, $e->to['is_tilen_input'], $e->to['input_choice']])->all(),
        ];
        $this->assertSame($pick($b), $pick($a));
        $this->assertSame([true, [['tilen', 'human', 'ui', true, 'Approve']]], [$pick($a)[0], $pick($a)[2]]);
        $this->assertSame($this->q($b)['state'], $this->q($a)['state']);

        // Same feed rows and ticket flags for the PM.
        $feed = collect($this->bearer($this->read, 'GET', '/api/ticket-comments/feed?project_id='.self::BH)->json('data'));
        $this->assertSame([[$a, 'Approve', true, true], [$b, 'Approve', true, true]], $feed->map(fn ($c) => [$c['ticket']['key'], $c['input_choice'], $c['is_owner_input'], $c['ticket']['needs_pm_pickup']])->all());

        // A non-JSON (Inertia) post redirects back like the drawer route.
        $c = $this->ask(['title' => 'Inertia']);
        $this->app['auth']->forgetGuards();
        $this->asOwner()->from('/projects/blue-harbor')->post("/projects/blue-harbor/questions/{$this->qid($c)}/answer", ['choice' => 'Hold'])
            ->assertStatus(303)->assertRedirect('/projects/blue-harbor');
        $this->assertSame('answered', $this->q($c)['state']);

        // A workspace member may answer too (as themselves), like on the drawer.
        $member = $this->workspaceUser(['name' => 'Mia Member']);
        $d = $this->ask(['title' => 'Member']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($member, 'web')->postJson("/projects/blue-harbor/questions/{$this->qid($d)}/answer", ['text' => 'Mine.'])
            ->assertCreated()->assertJsonPath('comment.author_id', 'user:'.$member->id);
    }

    #[Test]
    public function change_answer_before_pickup_and_409_after(): void
    {
        $a = $this->ask();
        $this->travelMinutes(1);
        $this->panelPost('answer', $a, ['choice' => 'Approve'])->assertCreated();
        $this->travelMinutes(1);
        $this->panelPost('answer', $a, ['choice' => 'Hold', 'text' => 'Wait for legal.'])->assertCreated()
            ->assertJsonPath('data.state', 'answered')->assertJsonPath('data.answer_choice', 'Hold')
            ->assertJsonPath('data.answer_text', 'Wait for legal.')->assertJsonPath('data.answered_at', Carbon::now()->toIso8601String());
        $ticketId = Ticket::query()->where('key', $a)->value('id');
        $this->assertSame(2, TicketComment::query()->where('ticket_id', $ticketId)->where('is_tilen_input', true)->count());

        $this->bearer($this->janice, 'POST', "/api/tickets/$a/ack-input")->assertOk();
        $this->panelPost('answer', $a, ['choice' => 'Approve'])->assertStatus(409)->assertJsonPath('message', 'Question is closed');
        $this->assertSame(2, TicketComment::query()->where('ticket_id', $ticketId)->count());
        $this->assertFalse(Ticket::query()->where('key', $a)->firstOrFail()->needsPmPickup());

        // Moved on: closed too.
        $b = $this->ask(['title' => 'Moved']);
        $this->bearer($this->janice, 'POST', "/api/tickets/$b/move", ['status' => 'done'])->assertOk();
        $this->panelPost('answer', $b, ['text' => 'Late'])->assertStatus(409);
    }

    #[Test]
    public function the_answer_is_validated_against_the_options(): void
    {
        $a = $this->ask();
        $free = $this->ask(['title' => 'Free', 'ask_options' => null]);

        $this->panelPost('answer', $a, ['choice' => 'Maybe'])->assertUnprocessable()->assertJsonValidationErrors(['choice' => 'Choose one of the options.']);
        $this->panelPost('answer', $a, ['choice' => 'other', 'text' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('choice');
        $this->panelPost('answer', $a, [])->assertUnprocessable()->assertJsonValidationErrors(['text' => 'Pick an option or write an answer.']);
        $this->panelPost('answer', $a, ['choice' => ' ', 'text' => '   '])->assertUnprocessable()->assertJsonValidationErrors('text');
        $this->panelPost('answer', $a, ['choice' => 'Hold', 'text' => str_repeat('x', 2001)])->assertUnprocessable()->assertJsonValidationErrors('text');
        $this->panelPost('answer', $free, ['choice' => 'Approve'])->assertUnprocessable()->assertJsonValidationErrors(['choice' => 'This question takes a written answer.']);
        $this->assertSame(0, TicketComment::query()->count());

        $this->panelPost('answer', $a, ['choice' => 'Hold', 'text' => str_repeat('x', 2000)])->assertCreated();
        $this->panelPost('answer', $free, ['text' => 'Free answer'])->assertCreated()->assertJsonPath('data.answer_text', 'Free answer');
    }

    // ---------------------------------------------------------------- dismiss / restore

    #[Test]
    public function dismiss_hides_it_from_the_owner_only_and_restore_brings_it_back(): void
    {
        $a = $this->ask();
        $b = $this->ask(['title' => 'Venue']);
        $this->assertSame(['portfolio' => 2, 'api' => 2, 'board' => 2, 'asks' => 2], $this->counts());
        $snapshot = fn () => Ticket::query()->where('key', $a)->firstOrFail()->toArray();
        $before = $snapshot();

        $this->travelMinutes(3);
        $this->panelPost('dismiss', $a)->assertOk()->assertJsonPath('data.state', 'dismissed')
            ->assertJsonPath('data.dismissed_at', Carbon::now()->toIso8601String());
        $this->panelPost('dismiss', $a)->assertOk()->assertJsonPath('data.state', 'dismissed'); // idempotent
        $this->assertSame(1, TicketEvent::query()->where('type', 'ask_dismissed')->count());
        $e = TicketEvent::query()->where('type', 'ask_dismissed')->firstOrFail();
        $this->assertSame(['tilen', 'human', 'ui', ['ask' => 'Approve staging?']], [$e->actor_id, $e->actor_type, $e->source, $e->to]);

        // Owner side: in the closed group, not counted. The open-ask count (agents' waiting column) is unchanged.
        $panel = $this->panel();
        $this->assertSame([[$b], [$a]], [array_column($panel['open'], 'ticket_key'), array_column($panel['closed'], 'ticket_key')]);
        $this->assertSame(['portfolio' => 1, 'api' => 1, 'board' => 1, 'asks' => 2], $this->counts());
        $this->assertSame([$a], array_column($this->panel(self::BH, 'dismissed')['closed'], 'ticket_key'));

        // The ticket is untouched: still waiting, no comment, no pickup.
        $this->assertSame($before, $snapshot());
        $this->assertSame(0, TicketComment::query()->count());
        $ticket = $this->bearer($this->read, 'GET', "/api/tickets/$a")->json('data');
        $this->assertSame(['waiting_tilen', false, false], [$ticket['status'], $ticket['needs_pm_pickup'], $ticket['owner_answered_ask']]);

        // Agents (MCP) still see it unanswered: open, no dismissal.
        $mcp = $this->tool($this->read, 'list_questions', ['project' => 'blue-harbor']);
        $this->assertSame([$a, $b], array_column($mcp['data']['open'], 'ticket_key'));
        $this->assertSame([null, null, null], [$mcp['data']['open'][0]['dismissed_at'], $mcp['data']['open'][0]['answered_at'], $mcp['data']['open'][0]['answer_choice']]);
        $this->assertSame([], $mcp['data']['closed']);
        $this->assertSame([$a, $b], array_column($this->tool($this->read, 'list_questions', ['project' => self::BH, 'state' => 'open'])['data']['open'], 'ticket_key'));
        $this->assertSame([$a, $b], array_column($this->tool($this->read, 'list_open_asks', ['project' => self::BH])['data'], 'key'));

        // Restore: open again and counted.
        $this->panelPost('restore', $a)->assertOk()->assertJsonPath('data.state', 'open')->assertJsonPath('data.dismissed_at', null);
        $this->panelPost('restore', $a)->assertOk()->assertJsonPath('data.state', 'open'); // idempotent
        $this->assertSame(['portfolio' => 2, 'api' => 2, 'board' => 2, 'asks' => 2], $this->counts());

        // A new ask after a dismissal is open again.
        $this->panelPost('dismiss', $a)->assertOk();
        $this->travelMinutes(1);
        $this->bearer($this->janice, 'PATCH', "/api/tickets/$a", ['ask' => 'Approve staging now?'])->assertOk();
        $this->assertSame(['open', null], [$this->q($a)['state'], $this->q($a)['dismissed_at']]);

        // Answering a dismissed question answers it.
        $this->panelPost('dismiss', $b)->assertOk();
        $this->panelPost('answer', $b, ['choice' => 'Hold'])->assertCreated()->assertJsonPath('data.state', 'answered');
        // Only open questions can be dismissed, only dismissed ones restored.
        $this->panelPost('dismiss', $b)->assertStatus(409)->assertJsonPath('message', 'Only an open question can be dismissed.');
        $this->panelPost('restore', $b)->assertStatus(409)->assertJsonPath('message', 'Only a dismissed question can be restored.');
        $this->bearer($this->janice, 'POST', "/api/tickets/$b/ack-input")->assertOk();
        $this->panelPost('dismiss', $b)->assertStatus(409);
    }

    // ---------------------------------------------------------------- scoping / auth

    #[Test]
    public function the_routes_are_scoped_to_the_signed_in_workspace(): void
    {
        $a = $this->ask();
        $nw = $this->ask(['title' => 'Northwind ask'], self::NW);
        [$marta, $martaToken, $martaKey] = $this->otherWorkspace();
        $qid = $this->qid($a);

        // Guests go to the login screen; nothing is written.
        $this->app['auth']->forgetGuards();
        foreach (['answer', 'dismiss', 'restore'] as $action) {
            $this->post("/projects/blue-harbor/questions/$qid/$action", ['text' => 'x'])->assertRedirect('/login');
        }
        $this->getJson('/api/projects/'.self::BH.'/questions')->assertUnauthorized();

        // Another workspace's user: 404 for Tilen's project, and Tilen's question id on their own project.
        $this->actingAs($marta, 'web');
        $this->postJson("/projects/blue-harbor/questions/$qid/answer", ['choice' => 'Approve'])->assertNotFound();
        $this->postJson("/projects/blue-harbor/questions/$qid/dismiss")->assertNotFound();
        $this->postJson("/projects/marta-lab/questions/$qid/answer", ['choice' => 'Approve'])->assertNotFound();
        $this->postJson("/projects/marta-lab/questions/$qid/dismiss")->assertNotFound();
        $this->bearer($martaToken, 'GET', '/api/projects/'.self::BH.'/questions')->assertNotFound();
        $this->assertSame("Unknown project 'blue-harbor'.", $this->toolError($martaToken, 'list_questions', ['project' => 'blue-harbor']));
        $this->assertSame(0, TicketComment::query()->count() + TicketEvent::query()->where('type', 'ask_dismissed')->count());

        // Their own works; Tilen does not see it.
        $this->app['auth']->forgetGuards();
        $this->actingAs($marta, 'web')->postJson("/projects/marta-lab/questions/{$this->qid($martaKey)}/answer", ['text' => 'Ok'])->assertCreated();
        $this->assertSame(['Marta secret ask?'], array_column($this->bearer($martaToken, 'GET', '/api/projects/proj-marta/questions')->json('data.answered'), 'text'));
        $this->bearer($this->read, 'GET', '/api/projects/proj-marta/questions')->assertNotFound();

        // Another project's question, unknown ids, a non-question ticket: 404.
        $this->panelPost('answer', $nw, ['choice' => 'Approve'], 'northwind')->assertCreated(); // sanity: on its own slug
        $this->app['auth']->forgetGuards();
        $this->asOwner()->postJson("/projects/blue-harbor/questions/{$this->qid($nw)}/answer", ['choice' => 'Approve'])->assertNotFound();
        $this->asOwner()->postJson('/projects/blue-harbor/questions/q-tk-nope/dismiss')->assertNotFound()->assertJsonPath('message', "Unknown question 'q-tk-nope'.");
        $todo = $this->create(['title' => 'Plain todo']);
        $this->asOwner()->postJson("/projects/blue-harbor/questions/{$this->qid($todo)}/dismiss")->assertNotFound();
    }

    #[Test]
    public function the_panel_and_counts_do_not_grow_queries_with_questions(): void
    {
        $this->ask();
        $this->ask(['title' => 'Two']);
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->panel();
            $this->counts();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $few = $queries();
        foreach (range(1, 6) as $i) {
            $k = $this->ask(['title' => "More $i"]);
            if ($i % 2 === 0) {
                $this->panelPost('answer', $k, ['choice' => 'Hold'])->assertCreated();
            }
            if ($i === 5) {
                $this->bearer($this->janice, 'POST', "/api/tickets/$k/move", ['status' => 'todo'])->assertOk();
            }
        }
        $this->assertSame($few, $queries());
    }
}
