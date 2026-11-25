<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tickets\Workstreams;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/** The open board's 60 s change poll: GET /projects/{slug}/board-stamp, an ETag that answers 304 while nothing changed. */
class BoardStampTest extends TestCase
{
    use RefreshDatabase;
    use TicketHelpers;

    private string $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
        $this->ws = Workstreams::ordered(Project::query()->findOrFail(self::BH))->first()->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function check(?string $etag = null, ?string $ws = null): TestResponse
    {
        return $this->asOwner()->getJson('/projects/blue-harbor/board-stamp?ws='.($ws ?? $this->ws), $etag ? ['If-None-Match' => $etag] : []);
    }

    /** The ETag of the current state, after asserting it is a fresh 200. */
    private function etag(?string $since = null): string
    {
        $res = $this->check($since)->assertOk();
        $this->assertNotSame($since, $res->headers->get('ETag'));

        return (string) $res->headers->get('ETag');
    }

    private function assertUnchanged(string $etag): void
    {
        $res = $this->check($etag)->assertStatus(304);
        $this->assertSame('', $res->getContent());
    }

    #[Test]
    public function the_stamp_answers_304_while_nothing_changed_and_matches_the_board_prop(): void
    {
        $this->api()->newTicket();
        $etag = $this->etag();
        $this->assertUnchanged($etag);
        $this->travelMinutes(5);
        $this->assertUnchanged($etag);

        $this->asOwner()->get('/projects/blue-harbor?ws='.$this->ws)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('board.stamp', trim($etag, '"')));
    }

    #[Test]
    public function a_ticket_update_comment_or_column_label_change_answers_200(): void
    {
        $key = $this->api()->newTicket()['key'];
        $etag = $this->etag();

        $this->travelMinutes(1);
        $this->asOwner()->patchJson("/projects/blue-harbor/tickets/$key", ['priority' => 'p1'])->assertOk();
        $etag = $this->etag($etag);
        $this->assertUnchanged($etag);

        $this->travelMinutes(1);
        $this->tilenSays($key, ['body' => 'Looks good'])->assertSuccessful();
        $etag = $this->etag($etag);
        $this->assertUnchanged($etag);

        $this->travelMinutes(1);
        $this->asOwner()->putJson('/projects/blue-harbor/column-labels', ['column_labels' => ['todo' => 'Next up']])->assertOk();
        $etag = $this->etag($etag);
        $this->assertUnchanged($etag);
    }

    #[Test]
    public function setting_or_clearing_the_waiting_on_input_flag_answers_200(): void
    {
        $key = $this->api()->newTicket()['key'];
        $etag = $this->etag();

        $this->travelMinutes(1);
        $this->api()->patchJson("/api/tickets/$key", ['waiting_on_input' => true, 'actor' => self::JANICE])->assertOk();
        $etag = $this->etag($etag);
        $this->assertUnchanged($etag);

        $this->travelMinutes(1);
        $this->api()->patchJson("/api/tickets/$key", ['waiting_on_input' => false, 'actor' => self::JANICE])->assertOk();
        $etag = $this->etag($etag);
        $this->assertUnchanged($etag);

        // The flag itself is in the stamp, not only the write around it (updated_at, the event).
        DB::table('tickets')->where('key', $key)->update(['waiting_on_input_at' => Carbon::now()->subDay()]);
        $etag = $this->etag($etag);
        DB::table('tickets')->where('key', $key)->update(['waiting_on_input_at' => Carbon::now()->subDays(2)]);
        $this->etag($etag);
    }

    #[Test]
    public function another_workspace_cannot_read_the_stamp(): void
    {
        $owner = User::factory()->create(['email' => 'grok@example.test']);
        Workspace::query()->create(['slug' => 'grok', 'name' => 'Grok', 'owner_user_id' => $owner->id]);

        $this->actingAs($owner, 'web')->getJson('/projects/blue-harbor/board-stamp?ws='.$this->ws)->assertNotFound();
    }
}
