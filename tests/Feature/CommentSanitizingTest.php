<?php

namespace Tests\Feature;

use App\Models\TicketComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/** Comment bodies are sanitized Markdown on every write path (CommentMarkdown::sanitize in TicketService::comment). */
class CommentSanitizingTest extends TestCase
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
    public function an_agent_posting_plain_text_through_the_api_gets_it_back_unchanged(): void
    {
        $key = $this->api()->newTicket()['key'];
        $body = "Checked the build.\nTwo things:\n- use `<div>` in the template\n- a < b holds, see https://example.com/x_(y)";

        $this->postJson("/api/tickets/$key/comments", ['body' => $body, 'actor' => self::JANICE])
            ->assertCreated()->assertJsonPath('data.body', $body);

        $this->assertSame($body, TicketComment::query()->sole()->body);
        $this->getJson("/api/tickets/$key/comments")->assertOk()->assertJsonPath('data.0.body', $body);
    }

    #[Test]
    public function raw_html_and_unsafe_links_are_neutralized_through_the_api(): void
    {
        $key = $this->api()->newTicket()['key'];

        $this->postJson("/api/tickets/$key/comments", ['body' => '<script>alert(1)</script> [x](javascript:alert(1))', 'actor' => self::JANICE])
            ->assertCreated()->assertJsonPath('data.body', '\<script>alert(1)\</script> [x]()');
    }

    #[Test]
    public function the_web_composer_markdown_is_sanitized_too(): void
    {
        $key = $this->api()->newTicket()['key'];

        $this->tilenSays($key, ['body' => "**Ship it** with [docs](https://example.com)\n\n- <img src=x onerror=alert(1)>\n- [bad](data:text/html,x)"])
            ->assertCreated()
            ->assertJsonPath('data.body', "**Ship it** with [docs](https://example.com)\n\n- \\<img src=x onerror=alert(1)>\n- [bad]()");
    }
}
