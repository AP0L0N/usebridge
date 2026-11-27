<?php

namespace Tests\Feature;

use App\Services\Tickets\ShareLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/**
 * Link previews (App\Support\LinkPreview): Open Graph + Twitter Card meta in the first HTML. A private ticket or board
 * URL tells a crawler nothing but the key in the URL; only a valid public share link carries the ticket title.
 */
class LinkPreviewTest extends TestCase
{
    use RefreshDatabase;
    use TicketHelpers;

    private const SLACK = 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)';

    private const IMAGE = 'https://bridge.example.test/og/bridge.png';

    /** Everything private a preview must never show. */
    private const SECRETS = ['Print the brochure', 'Ask the printer', 'Printer says Friday', 'Blue Harbor', 'General'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
        $this->api();
        $this->newTicket(['title' => 'Print the brochure', 'description' => "## Samples\n\nAsk the **printer** for [samples](https://example.com/x) & a `quote`.\n\n".str_repeat('More detail. ', 30)]);
        $this->postJson('/api/tickets/BH-1/comments', ['body' => 'Printer says Friday', 'actor' => self::JANICE])->assertCreated();
        $this->guest();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function guest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this;
    }

    private function crawl(string $uri, string $agent = self::SLACK): TestResponse
    {
        return $this->guest()->withHeaders(['User-Agent' => $agent])->get($uri);
    }

    private function assertPreview(TestResponse $response, string $title, string $description, string $url): void
    {
        foreach ([
            '<meta property="og:site_name" content="Bridge">',
            '<meta property="og:title" content="'.e($title).'">',
            '<meta property="og:description" content="'.e($description).'">',
            '<meta property="og:url" content="'.e($url).'">',
            '<meta property="og:image" content="'.self::IMAGE.'">',
            '<meta name="twitter:card" content="summary_large_image">',
            '<meta name="twitter:title" content="'.e($title).'">',
            '<meta name="twitter:image" content="'.self::IMAGE.'">',
        ] as $tag) {
            $response->assertSee($tag, false);
        }
    }

    #[Test]
    public function the_default_image_is_a_1200_by_630_png_in_public(): void
    {
        $size = getimagesize(public_path('og/bridge.png'));

        $this->assertSame([1200, 630, IMAGETYPE_PNG], [$size[0], $size[1], $size[2]]);
    }

    #[Test]
    public function a_crawler_gets_a_generic_ticket_preview_with_only_the_key(): void
    {
        foreach ([self::SLACK, 'Twitterbot/1.0', 'facebookexternalhit/1.1'] as $agent) {
            $response = $this->crawl('/projects/blue-harbor/tickets/BH-1', $agent)->assertOk();
            $this->assertPreview($response, 'Bridge ticket BH-1', 'Sign in to Bridge to open this ticket.', 'https://bridge.example.test/projects/blue-harbor/tickets/BH-1');
            $response->assertDontSee(self::SECRETS);
        }
        // The short form says the same, in upper case; nothing is looked up, so a ticket that does not exist reads alike.
        $this->assertPreview($this->crawl('/tickets/bh-1')->assertOk(), 'Bridge ticket BH-1', 'Sign in to Bridge to open this ticket.', 'https://bridge.example.test/tickets/bh-1');
        $this->assertPreview($this->crawl('/projects/nope/tickets/ZZ-999')->assertOk(), 'Bridge ticket ZZ-999', 'Sign in to Bridge to open this ticket.', 'https://bridge.example.test/projects/nope/tickets/ZZ-999');
        // Anything that is not a key is not repeated.
        $this->crawl('/projects/blue-harbor/tickets/Free%20money')->assertOk()
            ->assertSee('<meta property="og:title" content="Bridge ticket">', false)->assertDontSee('Free money');
    }

    #[Test]
    public function a_crawler_gets_a_generic_board_preview_without_the_project_or_workstream_name(): void
    {
        $response = $this->crawl('/projects/blue-harbor?ws=ws-1&done=all')->assertOk();

        $this->assertPreview($response, 'Bridge board', 'Sign in to Bridge to open this board.', 'https://bridge.example.test/projects/blue-harbor?ws=ws-1');
        $response->assertDontSee(self::SECRETS);
    }

    #[Test]
    public function a_person_without_a_session_still_goes_to_login_which_carries_the_generic_preview(): void
    {
        foreach (['/projects/blue-harbor/tickets/BH-1', '/projects/blue-harbor', '/tickets/BH-1'] as $uri) {
            $this->crawl($uri, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15')->assertRedirect(route('login'));
        }
        // Not a preview route: a crawler is a guest like any other.
        $this->crawl('/usage')->assertRedirect(route('login'));
        $this->guest()->withHeaders(['User-Agent' => self::SLACK])->post('/projects/blue-harbor/tickets', ['title' => 'x'])->assertRedirect(route('login'));

        $login = $this->crawl('/login')->assertOk();
        $this->assertPreview($login, 'Bridge', 'One board for every project your AI bots run.', 'https://bridge.example.test/login');
        $login->assertDontSee(self::SECRETS);
    }

    #[Test]
    public function a_logged_in_user_gets_the_app_even_with_a_crawler_user_agent(): void
    {
        $this->asOwner()->withHeaders(['User-Agent' => self::SLACK])->get('/projects/blue-harbor/tickets/BH-1')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Board')->where('ticket.key', 'BH-1'))
            ->assertSee('<meta property="og:title" content="Bridge">', false);
    }

    #[Test]
    public function a_public_share_link_previews_the_ticket_title_and_a_plain_text_summary(): void
    {
        $url = $this->asOwner()->postJson('/projects/blue-harbor/tickets/BH-1/share-links')->assertCreated()->json('data.url');
        $path = substr($url, strlen('https://bridge.example.test'));

        $response = $this->crawl($path)->assertOk();
        $summary = 'Samples Ask the printer for samples & a quote. '.str_repeat('More detail. ', 30);
        $summary = rtrim(mb_substr($summary, 0, 159)).'…';
        $this->assertPreview($response, 'Print the brochure', $summary, $url);
        $this->assertLessThanOrEqual(160, mb_strlen($summary));
        // The same for a browser: the meta is in the page itself.
        $this->assertPreview($this->crawl($path, 'Mozilla/5.0')->assertOk(), 'Print the brochure', $summary, $url);
    }

    #[Test]
    public function an_unavailable_share_link_gets_a_generic_preview(): void
    {
        $response = $this->crawl('/s/'.ShareLinks::newToken())->assertNotFound();

        // og:url is the site, not the token: every unavailable link answers the same page.
        $this->assertPreview($response, 'Bridge', 'This link has expired or is unavailable.', 'https://bridge.example.test/');
        $response->assertDontSee(self::SECRETS);
    }
}
