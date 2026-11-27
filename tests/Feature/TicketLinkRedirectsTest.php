<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/**
 * One canonical ticket URL (/projects/{slug}/tickets/{KEY}, TicketView::url); every other way a ticket link gets
 * written redirects to it, inside the viewer's workspace only.
 */
class TicketLinkRedirectsTest extends TestCase
{
    use RefreshDatabase;
    use TicketHelpers;

    private const CANONICAL = '/projects/blue-harbor/tickets/BH-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
        $this->api()->newTicket(['title' => 'Draft the brief']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_canonical_url_opens_the_ticket_on_its_board_and_is_what_the_api_returns(): void
    {
        $this->assertSame('https://bridge.example.test'.self::CANONICAL, $this->getJson('/api/tickets/BH-1')->assertOk()->json('data.url'));

        $this->asOwner()->get(self::CANONICAL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Board')
            ->where('ticket.key', 'BH-1'));
    }

    /** @return array<string, array{0: string}> */
    public static function alternateLinks(): array
    {
        return [
            'short key' => ['/tickets/BH-1'],
            'short key, lower case' => ['/tickets/bh-1'],
            'short id' => ['/tickets/{id}'],
            'singular' => ['/ticket/BH-1'],
            'singular under the project' => ['/projects/blue-harbor/ticket/BH-1'],
            'boards' => ['/boards/blue-harbor/tickets/BH-1'],
            'board, lower case' => ['/board/blue-harbor/tickets/bh-1'],
            'canonical path, lower case key' => ['/projects/blue-harbor/tickets/bh-1'],
            'canonical path, id' => ['/projects/blue-harbor/tickets/{id}'],
        ];
    }

    #[Test]
    #[DataProvider('alternateLinks')]
    public function an_alternate_link_redirects_to_the_canonical_url(string $uri): void
    {
        $uri = str_replace('{id}', (string) Ticket::query()->where('key', 'BH-1')->value('id'), $uri);

        $this->asOwner()->get($uri)->assertStatus(302)->assertRedirect(self::CANONICAL);
    }

    #[Test]
    public function a_redirect_keeps_the_query_string(): void
    {
        $this->asOwner()->get('/tickets/bh-1?done=all')->assertRedirect(self::CANONICAL.'?done=all');
    }

    #[Test]
    public function an_alternate_link_sends_a_guest_to_login_like_the_canonical_url(): void
    {
        $this->get('/tickets/BH-1')->assertRedirect(route('login'));
        $this->get(self::CANONICAL)->assertRedirect(route('login'));
    }

    #[Test]
    public function an_unknown_ticket_and_a_ticket_of_a_deleted_project_are_not_found(): void
    {
        $this->asOwner()->get('/tickets/BH-99')->assertNotFound();
        $this->get('/tickets/ZZ-1')->assertNotFound();
        $this->get('/tickets/not-a-key')->assertNotFound();
        $this->get('/projects/blue-harbor/tickets/BH-99')->assertNotFound();
        // The canonical path names the project: another project's slug is not this ticket.
        $this->get('/projects/northwind/tickets/BH-1')->assertNotFound();

        Project::query()->where('slug', 'blue-harbor')->firstOrFail()->delete();
        $this->get('/tickets/BH-1')->assertNotFound();
        $this->get(self::CANONICAL)->assertNotFound();
    }

    #[Test]
    public function a_ticket_of_another_workspace_is_not_found_and_never_redirects(): void
    {
        $stranger = User::factory()->create();
        Workspace::query()->create(['slug' => 'other', 'name' => 'Other', 'owner_user_id' => $stranger->id]);

        foreach (['/tickets/BH-1', '/tickets/bh-1', '/boards/blue-harbor/tickets/BH-1', self::CANONICAL] as $uri) {
            $this->actingAs($stranger, 'web')->get($uri)->assertNotFound();
        }
    }
}
