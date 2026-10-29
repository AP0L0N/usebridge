<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\RequiredInput;
use App\Models\User;
use App\Services\ProjectStatus;
use Database\Seeders\BridgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BridgeR4QuestionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->seed(BridgeSeeder::class);
        // Start every test with Merkwell smooth so question effects are isolated.
        RequiredInput::query()->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function api(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    private function gate(): void
    {
        $this->withSession(['bridge_gate_unlocked' => true]);
    }

    private function question(array $attrs = []): Question
    {
        return Question::create([
            'id' => 'q-'.uniqid(),
            'project_id' => 'proj-merkwell',
            'text' => 'Ship it?',
            'type' => 'yesno',
            'asked_by' => 'Tommy',
            ...$attrs,
        ]);
    }

    private function merkwell(): array
    {
        $ps = null;
        $this->get('/')->assertInertia(function (Assert $p) use (&$ps) {
            $p->component('Portfolio');
            $ps = $p->toArray()['props']['projects'];
        });

        return collect($ps)->firstWhere('id', 'proj-merkwell');
    }

    #[Test]
    public function questions_api_requires_sanctum(): void
    {
        $this->getJson('/api/questions')->assertUnauthorized();
        $this->postJson('/api/questions', [])->assertUnauthorized();
        $this->patchJson('/api/questions/x', [])->assertUnauthorized();
        $this->deleteJson('/api/questions/x')->assertUnauthorized();
    }

    #[Test]
    public function questions_validation(): void
    {
        $this->api();
        $this->postJson('/api/questions', [])->assertStatus(422)
            ->assertJsonValidationErrors(['project_id', 'text', 'type', 'asked_by']);
        $this->postJson('/api/questions', [
            'project_id' => 'nope', 'text' => str_repeat('a', 1001), 'type' => 'maybe',
            'asked_by' => str_repeat('a', 121), 'context' => str_repeat('c', 501),
        ])->assertStatus(422)->assertJsonValidationErrors(['project_id', 'text', 'type', 'asked_by', 'context']);
        $this->patchJson('/api/questions/missing', ['text' => 'x'])->assertNotFound();
        $this->deleteJson('/api/questions/missing')->assertNotFound();
        $this->getJson('/api/questions?state=bogus')->assertStatus(422)->assertJsonValidationErrors(['state']);

        $yn = $this->question();
        $tx = $this->question(['type' => 'text']);
        $this->patchJson("/api/questions/{$yn->id}", ['answer_choice' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors(['answer_choice']);
        $this->patchJson("/api/questions/{$yn->id}", ['answer_choice' => 'other'])->assertStatus(422)->assertJsonValidationErrors(['answer_text']);
        $this->patchJson("/api/questions/{$yn->id}", ['answer_text' => 'hi'])->assertStatus(422)->assertJsonValidationErrors(['answer_choice']);
        $this->patchJson("/api/questions/{$yn->id}", ['state' => 'answered'])->assertStatus(422)->assertJsonValidationErrors(['state']);
        $this->patchJson("/api/questions/{$tx->id}", ['answer_choice' => 'yes', 'answer_text' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['answer_choice']);
        $this->patchJson("/api/questions/{$tx->id}", ['answer_text' => '   '])->assertStatus(422)->assertJsonValidationErrors(['answer_text']);
        $this->assertSame('open', $yn->fresh()->state);
        $this->assertSame('open', $tx->fresh()->state);
    }

    #[Test]
    public function agent_can_create_update_list_and_delete_questions(): void
    {
        $this->api();
        $r = $this->postJson('/api/questions', [
            'project_id' => 'proj-merkwell', 'text' => 'Switch links to #1D5FA0?', 'type' => 'yesno',
            'asked_by' => 'Tommy', 'context' => 'AA contrast',
        ])->assertCreated()
            ->assertJsonPath('data.state', 'open')->assertJsonPath('data.type', 'yesno')
            ->assertJsonPath('data.asked_by', 'Tommy')->assertJsonPath('data.answered_at', null);
        $id = $r->json('data.id');
        $this->assertStringStartsWith('q-', $id);

        $this->patchJson("/api/questions/$id", ['text' => 'Switch body links to #1D5FA0?', 'context' => null])
            ->assertOk()->assertJsonPath('data.text', 'Switch body links to #1D5FA0?')->assertJsonPath('data.context', null)
            ->assertJsonPath('data.state', 'open');

        $this->getJson('/api/questions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);

        $this->deleteJson("/api/questions/$id")->assertNoContent();
        $this->assertNull(Question::find($id));
        $this->assertTrue(Question::query()->doesntExist());
    }

    #[Test]
    public function project_alias_creates_questions_by_id_or_slug(): void
    {
        $this->postJson('/api/projects/proj-merkwell/questions', ['text' => 'x', 'type' => 'text', 'asked_by' => 'Tommy'])->assertUnauthorized();

        $this->api();
        $this->assertSame('/api/projects/merkwell/questions', route('api.projects.questions.store', 'merkwell', false));
        $this->postJson('/api/projects/proj-merkwell/questions', ['text' => 'By id?', 'type' => 'yesno', 'asked_by' => 'Tommy', 'context' => 'ctx'])
            ->assertCreated()->assertJsonPath('data.project_id', 'proj-merkwell')->assertJsonPath('data.state', 'open')
            ->assertJsonPath('data.context', 'ctx');
        $r = $this->postJson('/api/projects/merkwell/questions', ['text' => 'By slug?', 'type' => 'text', 'asked_by' => 'Xerxes'])
            ->assertCreated()->assertJsonPath('data.project_id', 'proj-merkwell')->assertJsonPath('data.type', 'text');
        $this->assertStringStartsWith('q-', $r->json('data.id'));
        // project_id in the body is ignored; the URL wins.
        $this->postJson('/api/projects/merkwell/questions', ['project_id' => 'proj-concierge-avant', 'text' => 'URL wins', 'type' => 'yesno', 'asked_by' => 'Tommy'])
            ->assertCreated()->assertJsonPath('data.project_id', 'proj-merkwell');
        $this->getJson('/api/questions?project_id=proj-merkwell')->assertJsonCount(3, 'data');

        $this->postJson('/api/projects/merkwell/questions', [])->assertStatus(422)
            ->assertJsonValidationErrors(['text', 'type', 'asked_by'])->assertJsonMissingValidationErrors(['project_id']);
        $this->postJson('/api/projects/merkwell/questions', [
            'text' => str_repeat('a', 1001), 'type' => 'maybe', 'asked_by' => str_repeat('a', 121), 'context' => str_repeat('c', 501),
        ])->assertStatus(422)->assertJsonValidationErrors(['text', 'type', 'asked_by', 'context']);
        $this->postJson('/api/projects/nope/questions', ['text' => 'x', 'type' => 'text', 'asked_by' => 'Tommy'])->assertNotFound();
        $this->assertSame(3, Question::query()->count());
    }

    #[Test]
    public function patch_answer_sets_answered_at_and_reopen_clears_it(): void
    {
        $this->api();
        $q = $this->question();
        $this->patchJson("/api/questions/{$q->id}", ['answer_choice' => 'other', 'answer_text' => ' Use BOO15 '])
            ->assertOk()->assertJsonPath('data.state', 'answered')->assertJsonPath('data.answer_choice', 'other')
            ->assertJsonPath('data.answer_text', 'Use BOO15')
            ->assertJsonPath('data.answered_at', Carbon::now()->toIso8601String());

        $this->patchJson("/api/questions/{$q->id}", ['state' => 'open'])->assertOk()
            ->assertJsonPath('data.state', 'open')->assertJsonPath('data.answer_choice', null)
            ->assertJsonPath('data.answer_text', null)->assertJsonPath('data.answered_at', null);

        $t = $this->question(['type' => 'text']);
        $this->patchJson("/api/questions/{$t->id}", ['answer_text' => 'The 7 tips post'])->assertOk()
            ->assertJsonPath('data.state', 'answered')->assertJsonPath('data.answer_choice', null);

        $this->patchJson("/api/questions/{$t->id}", ['state' => 'closed'])->assertOk()->assertJsonPath('data.state', 'closed');
    }

    #[Test]
    public function get_filters_by_project_and_state_and_answers_are_readable(): void
    {
        $this->api();
        $open = $this->question();
        $answered = $this->question(['state' => 'answered', 'answer_choice' => 'yes', 'answered_at' => now()]);
        $closed = $this->question(['state' => 'closed']);
        $other = $this->question(['project_id' => 'proj-concierge-avant']);

        $this->getJson('/api/questions')->assertJsonCount(4, 'data');
        $this->getJson('/api/questions?state=all')->assertJsonCount(4, 'data');
        $this->getJson('/api/questions?project_id=proj-merkwell')->assertJsonCount(3, 'data');
        $this->getJson('/api/questions?project_id=proj-merkwell&state=open')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open->id);
        $this->getJson('/api/questions?state=open')->assertJsonCount(2, 'data');
        $this->getJson('/api/questions?state=closed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $closed->id);
        $this->getJson('/api/questions?state=answered')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $answered->id)->assertJsonPath('data.0.answer_choice', 'yes')
            ->assertJsonPath('data.0.answered_at', now()->toIso8601String());
        $this->assertNotNull($other->fresh());
    }

    #[Test]
    public function web_answer_route_sets_answered_state(): void
    {
        $q = $this->question();
        $this->post("/projects/merkwell/questions/{$q->id}/answer", ['answer_choice' => 'yes'])->assertRedirect('/gate');
        $this->assertSame('open', $q->fresh()->state);

        $this->gate();
        $this->from('/projects/merkwell')->post("/projects/merkwell/questions/{$q->id}/answer", ['answer_choice' => 'other'])
            ->assertSessionHasErrors('answer_text');
        $this->assertSame('open', $q->fresh()->state);

        $this->from('/projects/merkwell')->post("/projects/merkwell/questions/{$q->id}/answer", ['answer_choice' => 'no'])
            ->assertStatus(303)->assertRedirect('/projects/merkwell');
        $fresh = $q->fresh();
        $this->assertSame('answered', $fresh->state);
        $this->assertSame('no', $fresh->answer_choice);
        $this->assertTrue($fresh->answered_at->equalTo(now()));

        $t = $this->question(['type' => 'text']);
        $this->post("/projects/merkwell/questions/{$t->id}/answer", ['answer_text' => ''])->assertSessionHasErrors('answer_text');
        $this->post("/projects/merkwell/questions/{$t->id}/answer", ['answer_text' => 'Use the tips post'])->assertStatus(303);
        $this->assertSame('Use the tips post', $t->fresh()->answer_text);

        // Wrong project or closed question: 404.
        $this->post("/projects/concierge-avant/questions/{$q->id}/answer", ['answer_choice' => 'yes'])->assertNotFound();
        $c = $this->question(['state' => 'closed']);
        $this->post("/projects/merkwell/questions/{$c->id}/answer", ['answer_choice' => 'yes'])->assertNotFound();
    }

    #[Test]
    public function open_questions_make_the_project_wait_for_input(): void
    {
        $this->gate();
        $this->assertSame('smooth', $this->merkwell()['status']);

        $this->question(['state' => 'answered', 'answer_choice' => 'yes', 'answered_at' => now()]);
        $this->question(['state' => 'closed']);
        $this->assertSame('smooth', $this->merkwell()['status']);

        $q = $this->question();
        $q->forceFill(['created_at' => now()->subDays(4)])->save();
        $m = $this->merkwell();
        $this->assertSame('waiting', $m['status']);
        $this->assertSame(2, $m['level']);
        $this->assertSame(4, $m['ageDays']);

        // Oldest open item wins across inputs and questions.
        RequiredInput::create(['id' => 'in-x', 'project_id' => 'proj-merkwell', 'text' => 'x', 'waiting_on' => 'Tilen', 'since' => now()->subDays(8)]);
        $this->assertSame(3, $this->merkwell()['level']);
        RequiredInput::query()->delete();

        $this->assertSame(['waiting', 2], [$this->merkwell()['status'], $this->merkwell()['level']]);

        $this->post("/projects/merkwell/questions/{$q->id}/answer", ['answer_choice' => 'skip'])->assertStatus(303);
        $this->assertSame('smooth', $this->merkwell()['status']);
    }

    #[Test]
    public function portfolio_counts_open_questions(): void
    {
        $this->gate();
        $this->assertSame(0, $this->merkwell()['openQuestions']);
        $this->question();
        $this->question();
        $this->question(['state' => 'closed']);
        $this->question(['state' => 'answered', 'answer_choice' => 'yes', 'answered_at' => now()]);
        $this->assertSame(2, $this->merkwell()['openQuestions']);
    }

    #[Test]
    public function board_no_longer_lists_questions_and_keeps_their_rows(): void
    {
        $this->gate();
        $older = $this->question(['text' => 'older']);
        $older->forceFill(['created_at' => now()->subDay()])->save();
        $newer = $this->question(['text' => 'newer']);
        $this->question(['state' => 'closed']);
        // Same 2-day cut-off as cleared inputs (47h and 48h visible, 49h hidden).
        foreach ([['recent', 47], ['edge', 48], ['old', 49]] as [$id, $h]) {
            $this->question(['id' => $id, 'state' => 'answered', 'answer_choice' => 'yes', 'answered_at' => now()->subHours($h)]);
        }
        // Kanban: questions are superseded by Waiting on Tilen tickets; the board no longer lists them, the rows stay.
        $this->get('/projects/merkwell')->assertInertia(fn (Assert $p) => $p->component('Board')->missing('openQuestions')->missing('answeredQuestions'));
        $this->assertSame(2, Question::query()->where('project_id', 'proj-merkwell')->where('state', 'open')->count());
        $answered = ProjectStatus::recentlyDone(Question::query()->where('state', 'answered')->get(), 'answered_at');
        $this->assertSame(['recent', 'edge'], $answered->pluck('id')->all());
    }
}
