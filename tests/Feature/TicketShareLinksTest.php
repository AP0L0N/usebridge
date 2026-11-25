<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketShareLink;
use App\Models\User;
use App\Services\Tickets\ShareLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/** Public ticket share links: owner-only management, the /s/{token} page and its visitor comments. */
class TicketShareLinksTest extends TestCase
{
    use RefreshDatabase;
    use TicketHelpers;

    private const LINKS = '/projects/blue-harbor/tickets/BH-1/share-links';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTickets();
        $this->api();
        $this->newTicket(['title' => 'Print the brochure', 'description' => 'Ask the printer for samples.']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Creates a link as the workspace owner; returns [plain token, link payload]. @return array{0: string, 1: array<string, mixed>} */
    private function share(array $data = []): array
    {
        $link = $this->asOwner()->postJson(self::LINKS, $data)->assertCreated()->json('data');

        return [substr($link['url'], strlen('https://bridge.example.test/s/')), $link];
    }

    /** A guest visiting (no session user left over from the owner's requests). */
    private function guest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this;
    }

    private int $ip = 0;

    /** A visitor's comment; each from its own IP unless $ip is given (the per-IP limit has its own test). */
    private function postPublic(string $token, array $data = [], ?string $ip = null): TestResponse
    {
        $ip ??= '203.0.113.'.(++$this->ip % 250 + 1);

        return $this->guest()->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson("/s/$token/comments", ['name' => 'Mira Novak', 'body' => 'Looks great.', 'website' => '', ...$data]);
    }

    /** The comment form stamp from the public page, then enough time to pass the minimum time-to-submit. */
    private function stamp(string $token): string
    {
        $stamp = null;
        $this->guest()->get("/s/$token")->assertOk()->assertInertia(function (Assert $page) use (&$stamp) {
            $stamp = $page->toArray()['props']['stamp'];
        });
        Carbon::setTestNow(Carbon::now()->addSeconds(ShareLinks::MIN_SUBMIT_SECONDS + 2));

        return (string) $stamp;
    }

    #[Test]
    public function the_owner_creates_lists_edits_and_revokes_links(): void
    {
        [, $default] = $this->share();
        $this->assertSame(Carbon::now()->addDays(7)->toIso8601String(), $default['expires_at']);
        $this->assertFalse($default['allow_comments']);
        $this->assertTrue($default['active']);
        $this->assertMatchesRegularExpression('#^https://bridge\.example\.test/s/[A-Za-z0-9_-]{43}$#', $default['url']);

        [, $custom] = $this->share(['expires_in_days' => 30, 'allow_comments' => true]);
        $this->assertSame(Carbon::now()->addDays(30)->toIso8601String(), $custom['expires_at']);
        $this->assertTrue($custom['allow_comments']);
        [, $never] = $this->share(['never_expires' => true]);
        $this->assertNull($never['expires_at']);

        $this->postJson(self::LINKS, ['expires_in_days' => 0])->assertUnprocessable()->assertJsonValidationErrors('expires_in_days');
        $this->postJson(self::LINKS, ['expires_in_days' => 366])->assertUnprocessable();
        $this->postJson(self::LINKS, ['expires_in_days' => 3, 'never_expires' => true])->assertUnprocessable();

        // Several links per ticket, oldest first, each with its URL (copyable again later), expiry and comments setting.
        $list = $this->getJson(self::LINKS)->assertOk()->json('data');
        $this->assertSame([$default['url'], $custom['url'], $never['url']], array_column($list, 'url'));

        // Edit expiry (from now) and the comments setting.
        Carbon::setTestNow(Carbon::now()->addDay());
        $this->patchJson(self::LINKS."/{$default['id']}", ['expires_in_days' => 3])->assertOk()
            ->assertJsonPath('data.expires_at', Carbon::now()->addDays(3)->toIso8601String());
        $this->patchJson(self::LINKS."/{$default['id']}", ['never_expires' => true])->assertOk()->assertJsonPath('data.expires_at', null);
        $this->patchJson(self::LINKS."/{$default['id']}", ['allow_comments' => true])->assertOk()
            ->assertJsonPath('data.allow_comments', true)->assertJsonPath('data.expires_at', null);

        // Revoke: gone from the list; a revoked link can't be edited or revoked again.
        $this->deleteJson(self::LINKS."/{$custom['id']}")->assertOk()->assertJsonPath('data.revoked', true);
        $this->assertSame([$default['id'], $never['id']], array_column($this->getJson(self::LINKS)->json('data'), 'id'));
        $this->patchJson(self::LINKS."/{$custom['id']}", ['allow_comments' => false])->assertNotFound();
        $this->assertNotNull(TicketShareLink::query()->find($custom['id'])->revoked_at);

        // A link id of another ticket is not found under this one.
        $this->api()->newTicket(['title' => 'Other']);
        $this->asOwner()->getJson('/projects/blue-harbor/tickets/BH-2/share-links')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/projects/blue-harbor/tickets/BH-2/share-links/{$default['id']}", ['allow_comments' => false])->assertNotFound();
    }

    #[Test]
    public function only_the_workspace_owner_can_see_or_manage_links(): void
    {
        [, $link] = $this->share();
        $member = $this->workspaceUser();
        $this->actingAs($member, 'web');
        $this->getJson(self::LINKS)->assertForbidden();
        $this->postJson(self::LINKS)->assertForbidden();
        $this->patchJson(self::LINKS."/{$link['id']}", ['never_expires' => true])->assertForbidden();
        $this->deleteJson(self::LINKS."/{$link['id']}")->assertForbidden();

        // The owner of another workspace doesn't even find the ticket.
        $stranger = User::factory()->create();
        $stranger->ownedWorkspaces()->create(['slug' => 'elsewhere', 'name' => 'Elsewhere']);
        $this->actingAs($stranger, 'web')->getJson(self::LINKS)->assertNotFound();

        $this->guest()->get(self::LINKS)->assertRedirect(route('login'));
        $this->assertSame(1, TicketShareLink::query()->count());
        $this->assertNull(TicketShareLink::query()->first()->revoked_at);
    }

    #[Test]
    public function api_tokens_can_not_manage_links_and_ticket_payloads_carry_no_link_data(): void
    {
        $this->share();
        // Bearer tokens (even the owner's own) don't authenticate the web session routes; there is no API twin.
        $ownerToken = $this->workspaceOwner()->createToken('owner-bot', ['*'])->plainTextToken;
        $agentToken = $this->workspaceUser()->createToken(self::JANICE, ['*'])->plainTextToken;
        foreach ([$ownerToken, $agentToken] as $token) {
            $this->guest()->withToken($token)->getJson(self::LINKS)->assertUnauthorized();
            $this->guest()->withToken($token)->postJson(self::LINKS)->assertUnauthorized();
            $this->guest()->withToken($token)->get(self::LINKS)->assertRedirect(route('login'));
            $this->guest()->withToken($token)->postJson('/api/tickets/BH-1/share-links')->assertNotFound();
        }
        $this->assertSame(1, TicketShareLink::query()->count());

        // REST ticket, board and comments: no share link fields.
        $this->guest();
        Sanctum::actingAs($this->workspaceUser(), ['*']);
        $ticket = $this->getJson('/api/tickets/BH-1')->assertOk()->json('data');
        $this->assertArrayNotHasKey('shared', $ticket);
        $this->assertArrayNotHasKey('can_share', $ticket);
        $this->assertStringNotContainsString('share', strtolower((string) $this->getJson('/api/projects/'.self::BH.'/board')->getContent()));
    }

    #[Test]
    public function tokens_are_stored_only_as_a_sha256_hash_and_an_encrypted_copy(): void
    {
        [$token] = $this->share();
        $this->assertSame(43, strlen($token));
        $row = (array) DB::table('ticket_share_links')->first();
        $this->assertSame(hash('sha256', $token), $row['token_hash']);
        $this->assertNotSame($token, $row['token_encrypted']);
        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString($token, (string) $value, "plain token in $column");
        }
        $this->assertSame($token, TicketShareLink::query()->first()->token_encrypted);

        // Lookup by the token's hash: the token opens it, the hash itself does not.
        $this->guest()->get("/s/$token")->assertOk();
        $this->guest()->get('/s/'.$row['token_hash'])->assertNotFound();

        // Hidden from any serialization of the model.
        $this->assertArrayNotHasKey('token_hash', TicketShareLink::query()->first()->toArray());
        $this->assertArrayNotHasKey('token_encrypted', TicketShareLink::query()->first()->toArray());
    }

    #[Test]
    public function unknown_expired_revoked_or_archived_links_answer_the_same_404(): void
    {
        [$expired] = $this->share(['expires_in_days' => 1]);
        [$revoked, $revokedLink] = $this->share(['never_expires' => true]);
        [$archived] = $this->share(['never_expires' => true]);
        $this->guest()->get("/s/$expired")->assertOk();
        $this->asOwner()->deleteJson(self::LINKS."/{$revokedLink['id']}")->assertOk();

        Carbon::setTestNow(Carbon::now()->addDay()->addSecond());
        $responses = [
            'unknown' => $this->guest()->get('/s/'.ShareLinks::newToken()),
            'malformed' => $this->guest()->get('/s/not-a-token'),
            'expired' => $this->guest()->get("/s/$expired"),
            'revoked' => $this->guest()->get("/s/$revoked"),
        ];
        $this->asOwner()->postJson('/projects/blue-harbor/tickets/BH-1/archive')->assertOk();
        $responses['archived'] = $this->guest()->get("/s/$archived");

        $body = $responses['unknown']->assertNotFound()->getContent();
        $this->assertStringContainsString('This link has expired or is unavailable', $body);
        $this->assertStringNotContainsString('Print the brochure', $body);
        foreach ($responses as $why => $response) {
            $response->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
            $this->assertSame($body, $response->getContent(), $why);
        }

        // Expiry: the default 7 days, never.
        [$week] = $this->share();
        [$forever] = $this->share(['never_expires' => true]);
        Carbon::setTestNow(Carbon::now()->addDays(7)->subMinute());
        $this->asOwner()->postJson('/projects/blue-harbor/tickets/BH-1/unarchive')->assertOk();
        $this->guest()->get("/s/$week")->assertOk();
        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $this->guest()->get("/s/$week")->assertNotFound();
        Carbon::setTestNow(Carbon::now()->addYears(10));
        $this->guest()->get("/s/$forever")->assertOk();
        // Comments on an unavailable link are the same 404.
        $this->postPublic($expired)->assertNotFound();
        $this->assertSame(0, TicketComment::query()->where('author_type', 'public')->count());
    }

    #[Test]
    public function the_public_page_shows_only_the_allowlisted_fields(): void
    {
        $this->api();
        $other = $this->newTicket(['title' => 'Secret launch plan']);
        $this->patchJson('/api/tickets/BH-1', [
            'actor' => self::JANICE, 'assignee' => self::JANICE, 'priority' => 'p1', 'blocked_reason' => 'Printer closed',
            'labels' => ['print'], 'waiting_on_input' => true, 'ask' => 'Glossy or matte paper?', 'ask_options' => ['Glossy', 'Matte'],
            'description' => "See {$other['key']} and [the board](/projects/blue-harbor) or [docs](https://example.com/docs).",
        ])->assertOk();
        $this->postJson('/api/tickets/BH-1/comments', ['actor' => self::JANICE, 'body' => 'Samples ordered.'])->assertCreated();
        $this->tilenSays('BH-1', ['body' => 'Matte, please.', 'is_owner_input' => true])->assertCreated();
        Sanctum::actingAs($this->workspaceUser(), ['*']);
        $this->postJson('/api/tickets/BH-1/ack-input', ['actor' => self::JANICE])->assertOk();
        $retracted = $this->postJson('/api/tickets/BH-1/comments', ['actor' => self::JANICE, 'body' => 'Oops, wrong ticket.'])->json('data.id');
        $this->postJson("/api/comments/$retracted/retract", ['actor' => self::JANICE])->assertOk();

        [$token] = $this->share();
        $response = $this->guest()->get("/s/$token")->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertInertia(fn (Assert $page) => $page->component('Share/Show')
            ->where('share.project', 'Blue Harbor')
            ->where('share.workstream', 'General')
            ->where('share.column', 'To do')
            ->where('share.title', 'Print the brochure')
            ->where('share.comments_enabled', false)
            ->where('stamp', null)
            ->has('share.comments', 2)
            ->where('share.comments.0.author', 'Dr. Janice Polito')
            ->where('share.comments.0.body', 'Samples ordered.')
            ->where('share.comments.1.body', 'Matte, please.'));

        $props = $response->viewData('page')['props'];
        $this->assertSame(['errors', 'share', 'stamp', 'limits'], array_keys($props));
        $this->assertSame(['project', 'workstream', 'column', 'title', 'description', 'comments', 'comments_enabled'], array_keys($props['share']));
        $this->assertSame(['id', 'author', 'visitor', 'body', 'created_at'], array_keys($props['share']['comments'][0]));
        // Ticket references read as plain keys; no token, id or link to the other ticket.
        $this->assertStringStartsWith('See BH-2 and', $props['share']['description']);

        $html = $response->getContent();
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        foreach (['agent-janice', 'assignee', 'Glossy or matte', 'Printer closed', 'print"', 'p1', 'is_owner_input', 'is_tilen_input',
            'picked_up', 'Your input', 'Picked up', 'waiting_on_input', 'ask_options', 'Secret launch plan', $other['id'], 'Oops, wrong ticket',
            'tk-', '"auth"', '"features"', '"footer"', 'source', 'pm_agent'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, "leaked: $leak");
        }
        // The page renders in its own root view: no app shell or links into the app.
        $this->assertStringNotContainsString('/projects/blue-harbor/tickets', $html);
        $this->assertStringNotContainsString('href="/', str_replace('href="/brand/', '', $html));
    }

    #[Test]
    public function visitors_can_comment_only_when_the_link_allows_it(): void
    {
        [$token, $link] = $this->share();
        $this->postPublic($token, ['stamp' => 'x'])->assertForbidden();
        $this->asOwner()->patchJson(self::LINKS."/{$link['id']}", ['allow_comments' => true])->assertOk();
        $stamp = $this->stamp($token);
        $this->postPublic($token, ['stamp' => $stamp, 'name' => " <b>Mira</b>\u{0007}  Novak ", 'body' => "Love it <script>alert(1)</script>\n\n[x](javascript:alert(1))"])
            ->assertCreated()->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $c = TicketComment::query()->where('author_type', 'public')->sole();
        $this->assertSame('<b>Mira</b> Novak', $c->public_name); // stored as text, rendered escaped
        $this->assertSame("public:{$link['id']}", $c->author_id);
        $this->assertFalse($c->is_tilen_input);
        // Sanitized like every comment (CommentMarkdown): raw HTML escaped to text, unsafe link targets dropped.
        $this->assertSame("Love it \\<script>alert(1)\\</script>\n\n[x]()", $c->body);

        // Turned off again: 403, nothing stored.
        $this->asOwner()->patchJson(self::LINKS."/{$link['id']}", ['allow_comments' => false])->assertOk();
        $this->postPublic($token, ['stamp' => $stamp])->assertForbidden();
        $this->assertSame(1, TicketComment::query()->where('author_type', 'public')->count());

        // The thread shows it as a visitor's comment with their name.
        $this->guest()->get("/s/$token")->assertInertia(fn (Assert $page) => $page
            ->where('share.comments.0.author', '<b>Mira</b> Novak')->where('share.comments.0.visitor', true)->where('stamp', null));
        $this->asOwner()->get('/projects/blue-harbor/tickets/BH-1')->assertInertia(fn (Assert $page) => $page
            ->where('ticket.comments.0.author_type', 'public')
            ->where('ticket.comments.0.is_public', true)
            ->where('ticket.comments.0.author_name', '<b>Mira</b> Novak')
            ->where('ticket.comments.0.is_owner_input', false)
            ->where('ticket.comments.0.picked_up_at', null));
    }

    #[Test]
    public function public_comments_are_never_owner_input(): void
    {
        $this->api();
        $this->patchJson('/api/tickets/BH-1', ['actor' => self::JANICE, 'waiting_on_input' => true, 'ask' => 'Glossy or matte?'])->assertOk();
        [$token] = $this->share(['allow_comments' => true]);
        $stamp = $this->stamp($token);

        // Input flags in the body are ignored: always a plain comment.
        $this->postPublic($token, ['stamp' => $stamp, 'is_owner_input' => true, 'is_tilen_input' => true, 'input_choice' => 'Glossy'])->assertCreated();
        $c = TicketComment::query()->where('author_type', 'public')->sole();
        $this->assertFalse($c->is_tilen_input);
        $this->assertNull($c->input_choice);

        $ticket = Ticket::query()->where('key', 'BH-1')->first();
        $this->assertNull($ticket->tilen_input_at);
        $this->assertFalse($ticket->needsPmPickup());
        $this->assertTrue($ticket->waitingOnInputOutstanding());

        Sanctum::actingAs($this->workspaceUser(), ['*']);
        $api = $this->getJson('/api/tickets/BH-1')->assertOk();
        $api->assertJsonPath('data.waiting_on_input_outstanding', true)->assertJsonPath('data.needs_pm_pickup', false)
            ->assertJsonPath('data.owner_input_at', null);
        $this->getJson('/api/tickets?needs_pm_pickup=1')->assertOk()->assertJsonCount(0, 'data');
        // The answers relay never carries it, whatever the author filter.
        foreach (['humans', 'all', 'agents'] as $author) {
            $this->assertNotContains($c->id, array_column($this->getJson("/api/ticket-comments/feed?author=$author&after=0")->assertOk()->json('data'), 'id'), $author);
        }
        $this->assertSame([], $this->getJson('/api/ticket-comments/feed?inputs_only=1&author=all&after=0')->json('data'));

        // An owner input afterwards still clears the highlight as before.
        $this->tilenSays('BH-1', ['body' => 'Glossy.', 'is_owner_input' => true])->assertCreated();
        $this->assertFalse(Ticket::query()->where('key', 'BH-1')->first()->waitingOnInputOutstanding());
    }

    #[Test]
    public function the_comment_form_has_a_honeypot_a_minimum_time_and_length_limits(): void
    {
        [$token] = $this->share(['allow_comments' => true]);
        [$other] = $this->share(['allow_comments' => true]);

        // Sent right after opening the page: refused.
        $this->guest()->get("/s/$token")->assertOk();
        $fresh = ShareLinks::formStamp(TicketShareLink::query()->where('token_hash', ShareLinks::hash($token))->first());
        $this->postPublic($token, ['stamp' => $fresh])->assertUnprocessable()->assertJsonValidationErrors('body');

        $stamp = $this->stamp($token);
        // A missing, forged or other link's stamp: refused.
        $this->postPublic($token, ['stamp' => null])->assertUnprocessable()->assertJsonValidationErrors('stamp');
        $this->postPublic($token, ['stamp' => 'forged'])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postPublic($token, ['stamp' => $this->stamp($other)])->assertUnprocessable()->assertJsonValidationErrors('body');
        // Length limits and required fields.
        $this->postPublic($token, ['stamp' => $stamp, 'name' => str_repeat('a', ShareLinks::NAME_MAX + 1)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postPublic($token, ['stamp' => $stamp, 'body' => str_repeat('a', ShareLinks::BODY_MAX + 1)])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postPublic($token, ['stamp' => $stamp, 'name' => "\u{0007}  "], '203.0.113.8')->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postPublic($token, ['stamp' => $stamp, 'body' => ''], '203.0.113.8')->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertSame(0, TicketComment::query()->where('author_type', 'public')->count());

        // Honeypot filled: looks fine to the bot, nothing stored.
        $this->postPublic($token, ['stamp' => $stamp, 'website' => 'https://spam.example'], '203.0.113.9')->assertCreated();
        $this->assertSame(0, TicketComment::query()->where('author_type', 'public')->count());

        // Expired stamp (older than a day): refused.
        Carbon::setTestNow(Carbon::now()->addSeconds(ShareLinks::MAX_FORM_AGE_SECONDS + 1));
        $this->postPublic($token, ['stamp' => $stamp], '203.0.113.9')->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertSame(0, TicketComment::query()->where('author_type', 'public')->count());
    }

    #[Test]
    public function visitor_comments_are_rate_limited_per_ip_and_per_link(): void
    {
        [$token] = $this->share(['allow_comments' => true, 'never_expires' => true]);
        $stamp = $this->stamp($token);
        for ($i = 0; $i < 5; $i++) {
            $this->postPublic($token, ['stamp' => $stamp, 'body' => "Comment $i"], '192.0.2.10')->assertCreated();
        }
        $this->postPublic($token, ['stamp' => $stamp], '192.0.2.10')->assertStatus(429)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertSame(5, TicketComment::query()->where('author_type', 'public')->count());

        // Per link: 60 an hour whatever the IP.
        Carbon::setTestNow(Carbon::now()->addHours(2));
        $stamp = $this->stamp($token);
        for ($i = 0; $i < 60; $i++) {
            $this->postPublic($token, ['stamp' => $stamp, 'body' => "Comment $i"], '198.51.100.'.($i + 1))->assertCreated();
        }
        $this->postPublic($token, ['stamp' => $stamp], '192.0.2.200')->assertStatus(429);
        // Another link is not affected.
        [$other] = $this->share(['allow_comments' => true]);
        $this->postPublic($other, ['stamp' => $this->stamp($other)], '192.0.2.201')->assertCreated();
    }

    #[Test]
    public function the_card_shows_the_shared_icon_for_an_active_link_to_the_owner_only(): void
    {
        $sharedOn = fn () => collect($this->get('/projects/blue-harbor')->assertOk()->viewData('page')['props']['board']['columns'])
            ->flatMap(fn ($col) => $col['tickets'])->mapWithKeys(fn ($t) => [$t['key'] => $t['shared'] ?? null])->all();

        $this->api()->newTicket(['title' => 'Not shared']);
        $this->asOwner();
        $this->assertSame(['BH-1' => false, 'BH-2' => false], $sharedOn());

        [, $link] = $this->share(['expires_in_days' => 2]);
        $this->assertSame(['BH-1' => true, 'BH-2' => false], $sharedOn());
        $this->asOwner()->get('/projects/blue-harbor/tickets/BH-1')->assertInertia(fn (Assert $page) => $page->where('ticket.can_share', true));

        // Members see no share data at all.
        $this->actingAs($this->workspaceUser(), 'web');
        $this->assertSame(['BH-1' => null, 'BH-2' => null], $sharedOn());
        $this->get('/projects/blue-harbor/tickets/BH-1')->assertInertia(fn (Assert $page) => $page->where('ticket.can_share', false));

        // Expired, then revoked: no icon.
        Carbon::setTestNow(Carbon::now()->addDays(3));
        $this->asOwner();
        $this->assertSame(['BH-1' => false, 'BH-2' => false], $sharedOn());
        $this->patchJson(self::LINKS."/{$link['id']}", ['never_expires' => true])->assertOk();
        $this->assertSame(['BH-1' => true, 'BH-2' => false], $sharedOn());
        $this->deleteJson(self::LINKS."/{$link['id']}")->assertOk();
        $this->assertSame(['BH-1' => false, 'BH-2' => false], $sharedOn());
    }

    #[Test]
    public function the_board_reads_share_flags_in_one_query(): void
    {
        foreach (range(1, 5) as $i) {
            $this->api()->newTicket(['title' => "Ticket $i"]);
        }
        $this->share();
        $this->asOwner();
        DB::enableQueryLog();
        $this->get('/projects/blue-harbor')->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'ticket_share_links'));
        $this->assertCount(1, $queries);
    }

    #[Test]
    public function everything_but_the_share_routes_still_needs_a_login(): void
    {
        [$token] = $this->share();
        $this->guest();
        $this->get('/projects/blue-harbor')->assertRedirect(route('login'));
        $this->get('/projects/blue-harbor/tickets/BH-1')->assertRedirect(route('login'));
        $this->post('/projects/blue-harbor/tickets/BH-1/comments', ['body' => 'x'])->assertRedirect(route('login'));
        $this->get(self::LINKS)->assertRedirect(route('login'));
        $this->get('/usage')->assertRedirect(route('login'));
        $this->getJson('/api/tickets')->assertUnauthorized();
        $this->getJson('/api/tickets/BH-1')->assertUnauthorized();
        $this->getJson('/api/ticket-comments/feed')->assertUnauthorized();
        $this->get("/s/$token")->assertOk();

        // Any other path under /s/ is a plain 404, with the same headers.
        $this->get("/s/$token/attachments/1")->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get('/projects/blue-harbor')->assertHeaderMissing('X-Robots-Tag');

        // The only routes under /s/ are the two public share routes, in the web group (CSRF on the POST).
        $share = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 's/'));
        $this->assertSame(['share.show', 'share.comments.store'], $share->map->getName()->values()->all());
        foreach ($share as $route) {
            $this->assertContains('web', $route->gatherMiddleware());
        }
        $web = app('router')->getMiddlewareGroups()['web'];
        $this->assertNotEmpty(array_filter($web, fn ($m) => str_contains($m, 'Csrf') || str_contains($m, 'RequestForgery')));
    }
}
