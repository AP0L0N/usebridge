<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\AccountService;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\McpHelpers;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/**
 * r4 "Questions for you" (backend): open asks = live waiting_tilen tickets with an ask. The count on the portfolio,
 * board and project payloads, the list (board prop openAsks, GET /api/projects/{id}/asks, MCP list_open_asks) and the
 * answer through the existing owner input comment route. No questions table involved. Synthetic data.
 */
class BridgeR4OpenAsksTest extends TestCase
{
    use McpHelpers, RefreshDatabase, TicketHelpers;

    /** Per-agent token: acts as Janice. */
    private string $janice;

    /** Shared write token: `actor` optional. */
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

    private function create(string $token, array $data, string $project = self::BH): array
    {
        return $this->bearer($token, 'POST', "/api/projects/$project/tickets", ['title' => 'Draft the brief', ...$data])->assertCreated()->json('data');
    }

    /**
     * BH: two open asks (B moved in by Captain later than A was created waiting, so A is older) plus non-asks: an
     * archived waiting ticket, an in-progress ticket that carries an ask, a legacy waiting row without an ask, a todo.
     * NW: one open ask (via MCP). @return array{a: string, b: string, nw: string}
     */
    private function scenario(): array
    {
        $b = $this->create($this->janice, ['title' => 'Pick the venue', 'status' => 'todo'])['key'];
        $this->travelMinutes(10);
        $a = $this->create($this->janice, ['title' => 'Approve staging', 'status' => 'waiting_tilen', 'ask' => 'Approve staging?', 'ask_context' => 'QA passed.', 'ask_options' => ['Approve', 'Hold']])['key'];
        $this->travelMinutes(10);
        $this->bearer($this->shared, 'POST', "/api/tickets/$b/move", ['status' => 'waiting_tilen', 'ask' => 'Harbor hall or the loft?', 'actor' => self::CAPTAIN])->assertOk();

        $archived = $this->create($this->janice, ['title' => 'Old ask', 'status' => 'waiting_tilen', 'ask' => 'Still needed?'])['key'];
        $this->bearer($this->janice, 'POST', "/api/tickets/$archived/archive")->assertOk();
        $this->create($this->janice, ['title' => 'Parked ask', 'status' => 'in_progress', 'ask' => 'Later?']);
        $legacy = $this->create($this->janice, ['title' => 'Legacy wait', 'status' => 'waiting_tilen', 'ask' => 'x'])['key'];
        Ticket::query()->where('key', $legacy)->update(['ask' => null]); // pre-validation row: waiting without an ask
        $this->create($this->janice, ['title' => 'Plain todo']);

        $nw = $this->tool($this->shared, 'create_ticket', ['project' => self::NW, 'title' => 'Northwind pricing', 'status' => 'waiting_tilen', 'ask' => 'Which tier?', 'actor' => self::ANATOLY])['data']['key'];

        return ['a' => $a, 'b' => $b, 'nw' => $nw];
    }

    private function portfolioCounts(): array
    {
        $cards = null;
        $this->app['auth']->forgetGuards();
        $this->asOwner()->get('/')->assertOk()->assertInertia(function (Assert $p) use (&$cards) {
            $cards = $p->toArray()['props']['projects'];
        });

        return array_column($cards, 'openAsksCount', 'id');
    }

    private function asks(string $project = self::BH, ?string $token = null): array
    {
        return $this->bearer($token ?? $this->read, 'GET', "/api/projects/$project/asks")->assertOk()->json('data');
    }

    /** A second account with its own project and an open ask there. @return array{0: User, 1: string token} */
    private function otherWorkspace(): array
    {
        $marta = app(AccountService::class)->register('Marta', 'marta@example.test', 'correct horse battery', 'Marta Labs');
        $marta->markEmailAsVerified();
        $ws = Workspace::query()->where('owner_user_id', $marta->id)->firstOrFail();
        app(CurrentWorkspace::class)->runAs($ws, fn () => Agent::query()->create(['id' => 'agent-marta', 'name' => 'Marta Bot', 'role_label' => 'PM']));
        $seed = $marta->createToken('seed', ['*'])->plainTextToken;
        $this->bearer($seed, 'POST', '/api/projects', ['id' => 'proj-marta', 'name' => 'Marta Lab', 'slug' => 'marta-lab', 'owner_agent_id' => 'agent-marta'])->assertCreated();
        $token = $this->scopedToken($marta, $ws, 'write', 'agent-marta');
        $this->create($token, ['title' => 'Marta secret', 'status' => 'waiting_tilen', 'ask' => 'Marta secret ask?'], 'proj-marta');

        return [$marta, $token];
    }

    #[Test]
    public function the_count_is_on_the_portfolio_board_and_project_payloads(): void
    {
        $this->scenario();
        [, $martaToken] = $this->otherWorkspace();

        $this->assertSame([self::BH => 2, self::NW => 1], array_intersect_key($this->portfolioCounts(), [self::BH => 0, self::NW => 0]));
        $this->assertArrayNotHasKey('proj-marta', $this->portfolioCounts());

        $this->app['auth']->forgetGuards();
        $this->asOwner()->get('/projects/blue-harbor')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Board')->where('board.project.open_asks_count', 2)->has('openAsks', 2));

        $this->bearer($this->read, 'GET', '/api/projects/'.self::BH.'/board')->assertOk()->assertJsonPath('data.project.open_asks_count', 2);
        $this->bearer($this->read, 'GET', '/api/projects/northwind/board')->assertOk()->assertJsonPath('data.project.open_asks_count', 1);
        $projects = collect($this->bearer($this->read, 'GET', '/api/projects')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame([2, 1], [$projects[self::BH]['open_asks_count'], $projects[self::NW]['open_asks_count']]);
        $this->assertArrayNotHasKey('openAsksCount', $projects[self::BH]); // snake only over the API
        $this->bearer($this->read, 'GET', '/api/projects/blue-harbor')->assertOk()->assertJsonPath('data.open_asks_count', 2);
        $this->assertSame([2, 1], array_column(collect($this->tool($this->read, 'list_projects')['data'])->keyBy('id')->only([self::BH, self::NW])->values()->all(), 'open_asks_count'));

        // The other workspace sees only its own.
        $this->assertSame(['proj-marta' => 1], array_column($this->bearer($martaToken, 'GET', '/api/projects')->json('data'), 'open_asks_count', 'id'));
    }

    #[Test]
    public function the_list_is_lean_oldest_first_and_names_who_asked(): void
    {
        ['a' => $a, 'b' => $b] = $this->scenario();

        $list = $this->asks();
        $this->assertSame([$a, $b], array_column($list, 'key'));
        $this->assertSame([
            'id', 'key', 'project_id', 'title', 'ask', 'ask_context', 'ask_options', 'asked_by', 'asked_by_name', 'asked_by_type',
            'asked_by_source', 'asked_at', 'waiting_age_days', 'waiting_level', 'owner_input_at', 'needs_pm_pickup', 'owner_answered_ask', 'url',
        ], array_keys($list[0]));

        $ticketA = Ticket::query()->where('key', $a)->firstOrFail();
        $this->assertSame([$ticketA->id, 'Approve staging', 'Approve staging?', 'QA passed.', ['Approve', 'Hold']], [$list[0]['id'], $list[0]['title'], $list[0]['ask'], $list[0]['ask_context'], $list[0]['ask_options']]);
        $this->assertSame([self::JANICE, 'Dr. Janice Polito', 'agent', 'api'], [$list[0]['asked_by'], $list[0]['asked_by_name'], $list[0]['asked_by_type'], $list[0]['asked_by_source']]);
        $this->assertSame($ticketA->waiting_since->toIso8601String(), $list[0]['asked_at']);
        $this->assertSame([0, 1, false, false, null], [$list[0]['waiting_age_days'], $list[0]['waiting_level'], $list[0]['needs_pm_pickup'], $list[0]['owner_answered_ask'], $list[0]['owner_input_at']]);
        $this->assertStringEndsWith("/projects/blue-harbor/tickets/$a", $list[0]['url']);
        // B was created by Janice but moved into the waiting column (with the ask) by Captain: Captain asked.
        $this->assertSame([self::CAPTAIN, 'agent', 'api'], [$list[1]['asked_by'], $list[1]['asked_by_type'], $list[1]['asked_by_source']]);

        // Slug works like the other {id} paths; the MCP tool and the board prop return the same list.
        $this->assertSame($list, $this->asks('blue-harbor'));
        $this->assertSame($list, $this->tool($this->read, 'list_open_asks', ['project' => 'blue-harbor'])['data']);
        $nw = $this->tool($this->read, 'list_open_asks', ['project' => self::NW])['data'];
        $this->assertSame([self::ANATOLY, 'mcp'], [$nw[0]['asked_by'], $nw[0]['asked_by_source']]);
        $this->app['auth']->forgetGuards();
        $this->asOwner()->get('/projects/blue-harbor')->assertInertia(fn (Assert $p) => $p
            ->where('openAsks', fn ($asks) => collect($asks)->pluck('key')->all() === [$a, $b] && collect($asks)->first()['asked_by'] === self::JANICE));

        // Waiting longer: age and level follow waiting_since like the board cards.
        $this->travelMinutes(60 * 24 * 4);
        $this->assertSame([4, 2], [$this->asks()[0]['waiting_age_days'], $this->asks()[0]['waiting_level']]);

        // Empty project: [].
        $this->bearer($this->read, 'GET', '/api/projects/'.self::BH.'/asks')->assertOk();
        Ticket::query()->update(['archived_at' => now()]);
        $this->assertSame([], $this->asks());
        $this->assertSame(0, $this->bearer($this->read, 'GET', '/api/projects/'.self::BH.'/board')->json('data.project.open_asks_count'));
    }

    #[Test]
    public function asks_are_scoped_to_the_tokens_workspace_and_need_a_token(): void
    {
        $this->scenario();
        [$marta, $martaToken] = $this->otherWorkspace();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/projects/'.self::BH.'/asks')->assertUnauthorized();
        $this->bearer($this->read, 'GET', '/api/projects/proj-marta/asks')->assertNotFound()->assertJsonPath('message', "Unknown project 'proj-marta'.");
        $this->bearer($this->read, 'GET', '/api/projects/marta-lab/asks')->assertNotFound();
        $this->bearer($martaToken, 'GET', '/api/projects/'.self::BH.'/asks')->assertNotFound();
        $this->bearer($martaToken, 'GET', '/api/projects/blue-harbor/asks')->assertNotFound();
        $this->assertSame("Unknown project '".self::BH."'.", $this->toolError($martaToken, 'list_open_asks', ['project' => self::BH]));
        $this->assertSame("Unknown project 'proj-marta'.", $this->toolError($this->read, 'list_open_asks', ['project' => 'proj-marta']));
        $this->bearer($this->read, 'GET', '/api/projects/nope/asks')->assertNotFound();

        $mine = $this->asks('proj-marta', $martaToken);
        $this->assertSame(['Marta secret ask?'], array_column($mine, 'ask'));
        $this->assertSame('agent-marta', $mine[0]['asked_by']);
        $this->assertNotContains('Marta secret ask?', array_column($this->asks(), 'ask'));

        // A logged-in user of another workspace does not get Tilen's board (or its openAsks) either.
        $this->app['auth']->forgetGuards();
        $this->actingAs($marta, 'web')->get('/projects/blue-harbor')->assertNotFound();
    }

    #[Test]
    public function answering_uses_the_owner_input_comment_route_and_the_pm_move_clears_it(): void
    {
        ['a' => $a, 'b' => $b] = $this->scenario();
        $this->travelMinutes(5);

        // The panel's answer: the existing web route, exactly like the ticket's Your input box.
        $this->tilenSays($a, ['input_choice' => 'Approve', 'body' => 'Ship it.', 'is_owner_input' => true])
            ->assertCreated()->assertJsonPath('data.is_owner_input', true)->assertJsonPath('data.input_choice', 'Approve')
            ->assertJsonPath('data.author_id', 'tilen')->assertJsonPath('data.source', 'ui');
        $this->tilenSays($b, ['input_choice' => 'Maybe'])->assertUnprocessable()->assertJsonValidationErrors('input_choice');
        $this->tilenSays($b, ['input_choice' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('body');

        $e = TicketEvent::query()->where('type', 'commented')->latest('id')->firstOrFail();
        $this->assertSame(['tilen', 'human', 'ui', true, 'Approve'], [$e->actor_id, $e->actor_type, $e->source, $e->to['is_tilen_input'], $e->to['input_choice']]);

        // Existing semantics: the answer does not move the ticket, so the ask stays listed (answered, awaiting pickup).
        $list = collect($this->asks())->keyBy('key');
        $this->assertSame([true, true, Carbon::now()->toIso8601String()], [$list[$a]['needs_pm_pickup'], $list[$a]['owner_answered_ask'], $list[$a]['owner_input_at']]);
        $this->assertSame([false, false], [$list[$b]['needs_pm_pickup'], $list[$b]['owner_answered_ask']]);
        $this->bearer($this->read, 'GET', '/api/projects/'.self::BH.'/board')->assertJsonPath('data.project.open_asks_count', 2);
        $feed = $this->bearer($this->read, 'GET', '/api/ticket-comments/feed?project_id='.self::BH)->json('data');
        $this->assertSame([[$a, 'Approve', true]], array_map(fn ($c) => [$c['ticket']['key'], $c['input_choice'], $c['ticket']['needs_pm_pickup']], $feed));

        // A non-JSON (Inertia) post redirects back, as for the ticket modal.
        $this->app['auth']->forgetGuards();
        $this->asOwner()->from('/projects/blue-harbor')->post("/projects/blue-harbor/tickets/$b/comments", ['input_choice' => 'other', 'body' => 'The loft.'])
            ->assertStatus(303)->assertRedirect('/projects/blue-harbor');
        $this->assertTrue(collect($this->asks())->firstWhere('key', $b)['needs_pm_pickup']);

        // The PM picks up by moving the ticket out of the waiting column: acked, and the ask leaves the list and count.
        $this->bearer($this->janice, 'POST', "/api/tickets/$a/move", ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.needs_pm_pickup', false);
        $this->assertSame('input_acked', TicketEvent::query()->where('type', 'input_acked')->latest('id')->value('type'));
        $this->assertSame([$b], array_column($this->asks(), 'key'));
        $this->assertSame(1, $this->portfolioCounts()[self::BH]);

        // Acking without a move keeps the ask listed as answered (no blank form again); a new ask makes it unanswered.
        $this->bearer($this->janice, 'POST', "/api/tickets/$b/ack-input")->assertOk();
        $this->assertSame([false, true], [$this->asks()[0]['needs_pm_pickup'], $this->asks()[0]['owner_answered_ask']]);
        $this->travelMinutes(1);
        $this->bearer($this->janice, 'PATCH', "/api/tickets/$b", ['ask' => 'Loft on Friday or Saturday?'])->assertOk();
        $this->assertSame([false, 'Loft on Friday or Saturday?', self::JANICE], [$this->asks()[0]['owner_answered_ask'], $this->asks()[0]['ask'], $this->asks()[0]['asked_by']]);
    }
}
