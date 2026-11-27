<?php

namespace Tests\Feature;

use App\Services\Auth\AccountService;
use App\Support\Legal\LegalPages;
use Database\Seeders\BridgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\TicketHelpers;
use Tests\TestCase;

/** Logged-out landing page at `/`; logged-in users get the Portfolio. Copy is Marie's LANDING-COPY.md (27 Nov 2026). */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;
    use TicketHelpers;

    private const TRUST = 'Free · No card needed · Open source (AGPL-3.0)';

    private const OG_TITLE = 'One place for the work your AI bots run';

    private const OG_DESCRIPTION = 'Share a ticket with a public link. Split a project into workstreams. Bots post status over MCP or the API. Free, no card, open source.';

    private const COLUMNS = ['Backlog', 'To do', 'In progress', 'Review / QA', 'Done'];

    /** Copy that must never ship: unapproved drafts (origin story, why it's free, coffee) and the optional problem section. */
    private const DRAFTS = [
        'I built Bridge', 'it costs me little', 'costs little to run', 'Why is it free?', 'No catch.', 'What we don\'t do',
        'What we don&#039;t do', 'No ads.', 'No paid tier is planned', 'Buy me a coffee', 'buymeacoffee', 'If Bridge saves you time',
        'Coffees help pay', 'PLACEHOLDER', 'data-placeholder', 'Tilen to approve', 'id="support"',
        'Your bots do the work. Their updates end up everywhere.', 'Updates in too many places',
    ];

    private function html(): string
    {
        return $this->get('/')->assertOk()->getContent();
    }

    private function dom(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new \DOMXPath($dom);
    }

    /** The page's visible text (body only), entities decoded and whitespace collapsed. */
    private function text(string $html): string
    {
        $body = preg_replace('#^.*?<body[^>]*>|</body>.*$#s', '', $html);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5)));
    }

    #[Test]
    public function a_guest_sees_the_landing_page_with_its_key_headings_in_order(): void
    {
        $this->get('/')->assertOk()->assertViewIs('landing')->assertSeeTextInOrder([
            'One place for the work your AI bots run.',
            'Share a ticket with anyone, no sign-in.',
            'Pasted links show a preview card.',
            'Split a project into workstreams.',
            'A Kanban board you share with your bots.',
            'Every project in one Portfolio.',
            'A roster of your agents.',
            'Connect a bot with a token.',
            'See what your bots used.',
            'Free for everyone. No plans, no trial, no card.',
            'Read the code that runs your boards.',
            'Questions before you connect a bot',
            'Give your bots one place to report to.',
        ]);
        $this->assertSame('/', route('portfolio', [], false));
    }

    #[Test]
    public function the_required_copy_slots_are_on_the_page_verbatim(): void
    {
        $text = $this->text($this->html());

        foreach ([
            'Split a project into workstreams, and share a single ticket with a public link. Your bots post status and move tickets over MCP or the API. You step in when something is waiting on your input.',
            'Create a public link to one ticket. Anyone with the URL can read it, with no Bridge account. Paste it in Slack or a similar app and the preview card shows the title.',
            'The read-only page shows project, workstream, column, title, description and comments — not asks, assignees, agent ids or in-app links.',
            'You choose 1 to 365 days, or never. Revoke a link at any time; it stops at once. Expired and revoked links look the same to a visitor.',
            'Sample: a public ticket link unfurled in Slack, showing the title.',
            'A public /s/ link shows the ticket title, a short summary and the Bridge image. Any client can read that page, so the title is on the card.',
            'A private ticket or board link unfurls only as a generic card, such as “Bridge ticket CC-12” or “Bridge board”, never the title or comments.',
            'A workstream is a named board inside a project. Every project starts with one called General. Opening a project from the Portfolio takes you to its default workstream.',
            'Sample data: workstreams inside a project, with unread badges.',
            'This is a flag on a ticket in any column, not a sixth column. A bot can attach a question. You answer on the ticket.',
            'Sample data: five columns, and a ticket flagged waiting on your input.',
            'Each project shows Waiting input or Smooth sailing, the latest status note, who set it — including via MCP — and when it last moved.',
            'Sample data: a Portfolio with projects waiting for input at the top.',
            'The Agents page is a flat list of the real bots in your workspace, each with a name, title and full description.',
            'Go to Account → API tokens & MCP and create a token. Pick read, or read & write, and an expiry of 30, 90 or 365 days, or none.',
            'A read-only token can use the Read tools. Write tools need a read & write token. The limit is 120 requests a minute per token.',
            'Tested with Grok Bot. Works with any MCP client that can call a remote streamable-HTTP server with a Bearer token. Clients such as Gemini can use OAuth.',
            'Signed out? You\'ll create an account first, then land on your tokens.',
            'Usage shows token use by day, tool, agent and model. Claude Code (and Antigravity, once collected) is posted to the API. Cursor is a dashboard CSV import, shown as one account, not split by agent.',
            'Everything in the product: projects, workstreams, boards, share links, tokens, MCP and the API.',
            'Still stuck? Email support@usebridge.si.',
        ] as $slot) {
            $this->assertStringContainsString($slot, $text);
        }
    }

    #[Test]
    public function the_faq_has_eight_items_and_the_first_answer_is_maries_slot_without_extra_terms_lines(): void
    {
        $html = $this->html();
        $xp = $this->dom($html);

        $questions = array_map(fn (\DOMNode $n) => trim($n->textContent), iterator_to_array($xp->query('//div[@class="faq"]/details/summary')));
        $this->assertSame([
            'Who can see my boards and data?',
            'What does a share link show, and how long does it last?',
            'Which bots work with Bridge?',
            'How much setup is it?',
            'Does it cost my bot tokens?',
            'What if Bridge shuts down?',
            'Can I delete my account?',
            'Can I invite my team?',
        ], $questions);
        $this->assertSame(8, substr_count($html, '<details'));
        $this->assertMatchesRegularExpression('#<div class="faq">\s*<details open>\s*<summary><span>Who can see my boards and data\?</span>#', $html);
        $this->assertSame(1, substr_count($html, '<details open'));

        $first = preg_replace('/\s+/u', ' ', trim($xp->query('//div[@class="faq"]/details[1]/p')->item(0)->textContent));
        $this->assertSame('Nobody else sees your workspace in the app. Our admin view shows only your name, email, sign-up date, last login and status, never your boards or usage. People who run the server can technically reach the database, but only to run, secure or repair the service, or when the law requires it. Tokens are hashed, and can be read-only, set to expire, and revoked. Data is stored in the EU (Hetzner, Helsinki, Finland). See our Privacy policy and Terms.', $first);
        $this->assertStringNotContainsStringIgnoringCase('train AI', $html);
        $this->assertStringNotContainsStringIgnoringCase('sell your content', $html);
    }

    #[Test]
    public function the_landing_meta_is_server_rendered_with_the_landing_preview_image(): void
    {
        $xp = $this->dom($this->html());
        $meta = fn (string $attr, string $name): ?string => $xp->query('//head/meta[@'.$attr.'="'.$name.'"]')->item(0)?->getAttribute('content');

        $this->assertSame('Bridge: one place for the work your AI bots run', trim($xp->query('//head/title')->item(0)->textContent));
        $this->assertSame('A free board for projects you run with AI bots. Split work into workstreams, share a ticket with a public link, and step in when it is waiting on your input.', $meta('name', 'description'));
        $this->assertSame('website', $meta('property', 'og:type'));
        $this->assertSame('Bridge', $meta('property', 'og:site_name'));
        $this->assertSame(self::OG_TITLE, $meta('property', 'og:title'));
        $this->assertSame(self::OG_DESCRIPTION, $meta('property', 'og:description'));
        $this->assertSame('https://usebridge.si/', $meta('property', 'og:url'));
        $this->assertSame('https://usebridge.si/og/landing.png', $meta('property', 'og:image'));
        $this->assertSame('1200', $meta('property', 'og:image:width'));
        $this->assertSame('630', $meta('property', 'og:image:height'));
        $this->assertSame('summary_large_image', $meta('name', 'twitter:card'));
        $this->assertSame(self::OG_TITLE, $meta('name', 'twitter:title'));
        $this->assertSame(self::OG_DESCRIPTION, $meta('name', 'twitter:description'));
        $this->assertSame('https://usebridge.si/og/landing.png', $meta('name', 'twitter:image'));

        // The landing image is its own file; the generic ticket / board / share preview image is left as it was.
        $this->assertSame([1200, 630], array_slice(getimagesize(public_path('og/landing.png')), 0, 2));
        $this->assertSame([1200, 630], array_slice(getimagesize(public_path('og/bridge.png')), 0, 2));
        $this->assertNotSame(md5_file(public_path('og/bridge.png')), md5_file(public_path('og/landing.png')));
    }

    #[Test]
    public function the_landing_page_links_to_signup_and_login(): void
    {
        $this->get('/')
            ->assertSee('href="'.route('signup.show').'"', false)
            ->assertSee('href="'.route('login.show').'"', false);
    }

    #[Test]
    public function the_calls_to_action_point_where_the_copy_says(): void
    {
        $xp = $this->dom($this->html());
        $href = fn (string $query): array => array_map(fn (\DOMElement $a) => $a->getAttribute('href'), iterator_to_array($xp->query($query)));

        $this->assertSame([route('signup.show')], $href('//section[@id="hero"]//a[normalize-space()="Create a free account"]'));
        $this->assertSame(['#share'], $href('//section[@id="hero"]//a[normalize-space()="See how tickets are shared"]'));
        $this->assertSame([route('signup.show')], $href('//section[@id="connect"]//a[normalize-space()="Connect your bot"]'));
        $this->assertSame(['https://github.com/AP0L0N/usebridge'], $href('//section[@id="connect"]//a[normalize-space()="Read the MCP docs on GitHub"]'));
        $this->assertSame(['https://github.com/AP0L0N/usebridge'], $href('//section[@id="code"]//a[normalize-space()="View on GitHub"]'));
        $this->assertSame([route('signup.show')], $href('//section[contains(@class,"final")]//a[normalize-space()="Create a free account"]'));
        $this->assertSame(['#connect'], $href('//section[contains(@class,"final")]//a[normalize-space()="Connect your bot"]'));
        $this->assertSame([route('signup.show')], $href('//*[@data-sticky-cta]//a[normalize-space()="Create a free account"]'));
    }

    #[Test]
    public function the_connect_section_lists_the_fifteen_mcp_tools_and_the_mcp_json_example(): void
    {
        $html = $this->html();
        $xp = $this->dom($html);
        $tools = fn (int $group): array => array_map(fn (\DOMNode $n) => $n->textContent, iterator_to_array($xp->query('//section[@id="connect"]//div[@class="group"]['.$group.']//li')));

        $this->assertSame('What your bot can do: 15 MCP tools', trim($xp->query('//section[@id="connect"]//p[@class="tools-title"]')->item(0)->textContent));
        $this->assertSame('Read (9)', trim($xp->query('//section[@id="connect"]//div[@class="group"][1]/h3')->item(0)->textContent));
        $this->assertSame(['list_projects', 'get_board', 'list_tickets', 'list_workstreams', 'list_open_asks', 'list_questions', 'get_ticket', 'list_agents', 'list_labels'], $tools(1));
        $this->assertSame('Write (6)', trim($xp->query('//section[@id="connect"]//div[@class="group"][2]/h3')->item(0)->textContent));
        $this->assertSame(['create_ticket', 'update_ticket', 'move_ticket', 'comment_ticket', 'post_status', 'set_project_status'], $tools(2));
        $this->assertStringContainsString('Example mcp.json', $html);
        $this->assertStringContainsString('"url": "https://usebridge.si/mcp",', $html);
        $this->assertStringContainsString('"headers": { "Authorization": "Bearer &lt;your token&gt;" }', $html);
    }

    #[Test]
    public function a_logged_in_user_still_gets_the_portfolio(): void
    {
        Notification::fake();
        $user = app(AccountService::class)->register('Ada Acme', 'ada@example.test', 'acme-studio-planner-27')->fresh();

        $this->actingAs($user)->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portfolio'));
    }

    #[Test]
    public function the_signed_in_owner_still_gets_the_portfolio(): void
    {
        $this->seed(BridgeSeeder::class);

        $this->asOwner()->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portfolio'));
    }

    #[Test]
    public function guarded_pages_still_redirect_guests_to_login(): void
    {
        $this->get('/usage')->assertRedirect(route('login'));
        $this->get('/account')->assertRedirect(route('login'));
    }

    #[Test]
    public function there_are_no_exclamation_marks_no_off_limits_claims_and_no_external_host_but_github(): void
    {
        $html = $this->html();
        $text = $this->text($html);

        $this->assertStringNotContainsString('!', $text);
        foreach (['polymarket', 'power saver', 'questions panel', 'staging', 'pricing', 'changelog', 'jira', 'testimonial', 'on track'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $text, $word);
        }
        $this->assertStringNotContainsStringIgnoringCase('changelog', $html);
        $this->assertStringNotContainsStringIgnoringCase('polymarket', file_get_contents(resource_path('css/landing.css')));
        $this->assertDoesNotMatchRegularExpression('/<script[^>]+src=["\']?(https?:)?\/\//i', $html);
        $this->assertStringNotContainsString('figma.com', $html.file_get_contents(resource_path('css/landing.css')));

        $own = parse_url(url('/'), PHP_URL_HOST).(parse_url(url('/'), PHP_URL_PORT) ? ':'.parse_url(url('/'), PHP_URL_PORT) : '');
        preg_match_all('/\b(?:src|href)=["\']?(?:https?:)?\/\/([^\/"\'\s>#]+)/i', $html, $m);
        foreach ($m[1] as $host) {
            $this->assertContains($host, ['github.com', $own], 'external reference to '.$host);
        }
    }

    #[Test]
    public function the_trust_line_is_final_unlinked_and_shared_by_hero_and_final_cta(): void
    {
        $html = $this->html();

        $this->assertSame(__('landing.trust_line'), self::TRUST);
        preg_match_all('#<p class="trust">(.*?)</p>#su', $html, $m);
        $this->assertCount(2, $m[1]);
        foreach ($m[1] as $line) {
            $this->assertSame(self::TRUST, trim(strip_tags($line)));
            $this->assertStringNotContainsString('<a ', $line);
        }

        // In the hero, the trust line sits under the buttons.
        $this->assertLessThan(strpos($html, '<p class="trust">'), strpos($html, 'See how tickets are shared'));
    }

    #[Test]
    public function the_hero_mock_is_static_and_nothing_on_the_page_animates(): void
    {
        $html = $this->html();
        $xp = $this->dom($html);

        $this->assertNotNull($xp->query('//section[@id="hero"]//figure[@data-slot="hero-mock"]')->item(0));
        $this->assertSame('Sample data', trim($xp->query('//figure[@data-slot="hero-mock"]/figcaption')->item(0)->textContent));
        $this->assertLessThan(strpos($html, 'id="hero-mock"'), strpos($html, 'id="hero-title"'));

        // No looping hero component, no animation script or stylesheet, and no keyframes on the landing.
        $this->assertStringNotContainsString('bridge-hero', $html);
        $this->assertStringNotContainsString('data-bridge-hero', $html);
        $this->assertFileDoesNotExist(public_path('landing/hero/bridge-hero.js'));
        $this->assertFileDoesNotExist(public_path('landing/hero/bridge-hero.css'));
        $css = file_get_contents(resource_path('css/landing.css'));
        $this->assertStringNotContainsString('@keyframes', $css);
        $this->assertDoesNotMatchRegularExpression('/(?<![-\w])animation(-name)?\s*:\s*(?!none)[a-z]/i', $css);
        $this->assertStringNotContainsString('requestAnimationFrame', file_get_contents(resource_path('js/landing.ts')));

        // The animation's alt text describes motion, so it isn't attached to the static mock.
        $this->assertStringNotContainsString('The loop then repeats', $html);
        $this->assertStringNotContainsString('Animated example board', $html);
    }

    #[Test]
    public function every_board_mock_has_the_five_real_columns_and_waiting_is_a_flag_not_a_column(): void
    {
        $html = $this->html();
        $xp = $this->dom($html);

        $boards = $xp->query('//div[contains(concat(" ", @class, " "), " mk-board ")]');
        $this->assertSame(2, $boards->length);
        foreach ($boards as $board) {
            $labels = [];
            foreach ($xp->query('./div[@class="mk-col"]/h4', $board) as $h4) {
                $labels[] = trim($h4->firstChild->nextSibling->textContent);
            }
            $this->assertSame(self::COLUMNS, $labels);
            $this->assertSame(1, $xp->query('.//p[contains(@class,"mk-flag")]', $board)->length);
        }

        // The flag is always "Waiting on your input"; "Waiting on you" never appears on its own (no column, no count).
        $text = $this->text($html);
        $this->assertDoesNotMatchRegularExpression('/waiting on you\b/i', str_ireplace('waiting on your input', '', $text));
        $this->assertStringContainsString('Waiting input', $text);
        $this->assertStringContainsString('Smooth sailing', $text);
    }

    #[Test]
    public function board_mocks_scroll_inside_themselves_without_widening_the_page(): void
    {
        $html = $this->html();
        $xp = $this->dom($html);

        // Cards carry absolute .sr-only text. Unless the scroller is positioned, that text escapes its overflow clip
        // and widens the page on small screens (390px rendered 578px wide).
        $this->assertGreaterThan(0, $xp->query('//div[contains(concat(" ", @class, " "), " mk-board ")]//*[@class="sr-only"]')->length);
        preg_match('/^\.mk-board \{([^}]*)\}/m', file_get_contents(resource_path('css/landing.css')), $rule);
        $this->assertNotEmpty($rule);
        $this->assertStringContainsString('position: relative', $rule[1]);
        $this->assertStringContainsString('overflow-x: auto', $rule[1]);
    }

    #[Test]
    public function every_mock_is_labelled_as_sample_data(): void
    {
        $xp = $this->dom($this->html());

        $mocks = $xp->query('//figure[contains(concat(" ", @class, " "), " mock ")]');
        $this->assertGreaterThanOrEqual(6, $mocks->length);
        foreach ($mocks as $mock) {
            $this->assertStringStartsWith('Sample', trim($xp->query('./figcaption', $mock)->item(0)->textContent));
        }
    }

    #[Test]
    public function the_draft_blocks_are_not_rendered_with_the_flag_off_or_on(): void
    {
        foreach ([false, true] as $flag) {
            config(['bridge.landing_drafts' => $flag]);
            $html = $this->html();

            foreach (self::DRAFTS as $text) {
                $this->assertStringNotContainsString($text, $html, $text);
            }
            $this->assertStringContainsString('cards cards--2', $html);
        }
    }

    #[Test]
    public function the_drafts_flag_reads_bridge_landing_show_drafts_and_defaults_off(): void
    {
        $src = file_get_contents(config_path('bridge.php'));
        $this->assertStringContainsString("env('BRIDGE_LANDING_SHOW_DRAFTS', false)", $src);
        $this->assertStringNotContainsString("env('BRIDGE_LANDING_PENDING'", $src);
    }

    #[Test]
    public function lime_is_only_used_by_the_hero_cta_and_the_waiting_on_your_input_highlights(): void
    {
        $xp = $this->dom($this->html());

        // Every element with the signal button class is the hero primary CTA, exactly once.
        $signal = $xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " btn--signal ")]');
        $this->assertSame(1, $signal->length);
        $this->assertSame('Create a free account', trim($signal->item(0)->textContent));
        $this->assertNotNull($xp->query('//section[@id="hero"]//a[contains(@class,"btn--signal")]')->item(0));

        // Waiting highlights: every one names the flag "Waiting on your input".
        $highlights = $xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " is-waiting ")]');
        $this->assertGreaterThan(0, $highlights->length);
        foreach ($highlights as $el) {
            $this->assertStringStartsWith('Waiting on your input', trim($el->textContent), 'unexpected waiting highlight');
        }
        $this->assertSame(0, $xp->query('//header//*[contains(@class,"btn--signal") or contains(@class,"is-waiting")]')->length);
        $this->assertSame(0, $xp->query('//*[@data-sticky-cta]//*[contains(@class,"btn--signal")]')->length);
        $this->assertSame(0, $xp->query('//section[contains(@class,"final")]//*[contains(@class,"btn--signal")]')->length);

        // The stylesheet uses --signal only inside whitelisted selectors; no raw lime hex outside tokens.css.
        $css = file_get_contents(resource_path('css/landing.css'));
        $this->assertDoesNotMatchRegularExpression('/#c8f135/i', $css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        $uses = 0;
        foreach ($rules as [, $selector, $body]) {
            if (str_contains($body, 'var(--signal)')) {
                $uses++;
                $this->assertMatchesRegularExpression('/\.btn--signal|\.is-waiting/', $selector, 'lime outside whitelist: '.trim($selector));
            }
        }
        $this->assertGreaterThan(0, $uses);
    }

    #[Test]
    public function the_footer_has_maries_columns_and_the_shared_legal_copy(): void
    {
        config(['bridge.legal_entity' => 'Example Studio', 'bridge.source_url' => 'https://github.com/AP0L0N/usebridge', 'bridge.source_sha' => null]);
        $html = $this->html();
        $xp = $this->dom($html);
        $links = fn (string $heading): array => array_map(
            fn (\DOMElement $a) => [trim($a->textContent), $a->getAttribute('href')],
            iterator_to_array($xp->query('//footer//div[@class="footer-cols"]/div[h2="'.$heading.'"]//a')),
        );

        $this->assertSame('One place for the work your AI bots run.', trim($xp->query('//footer//div[@class="about"]/p')->item(0)->textContent));
        $this->assertSame([
            ['Share a ticket', '#share'], ['Workstreams', '#workstreams'], ['Boards', '#boards'], ['Portfolio', '#portfolio'],
            ['Agents', '#agents'], ['Connect', '#connect'], ['FAQ', '#faq'], ['Sign in', route('login.show')],
        ], $links('Product'));
        $this->assertSame([['Source code (AGPL-3.0-or-later)', 'https://github.com/AP0L0N/usebridge']], $links('Open source'));
        $this->assertSame([['Privacy policy', url('/privacy')], ['Terms', url('/terms')], ['Contact / imprint', url('/imprint')]], $links('Legal'));
        $this->assertSame('Questions: support@usebridge.si', trim($xp->query('//footer//p[@class="contact"]')->item(0)->textContent));

        // The shared legal footer (LegalPages) is unchanged: no year, same links, same source link.
        $footer = LegalPages::footer(null);
        foreach ($footer['links'] as $link) {
            $this->assertStringContainsString('<a href="'.$link['href'].'">'.$link['label'].'</a>', $html);
        }
        $this->assertSame(['/imprint', '/terms', '/privacy'], array_column($footer['links'], 'href'));
        $this->assertStringContainsString('© Example Studio · Bridge is free software under AGPL-3.0-or-later. <a href="https://github.com/AP0L0N/usebridge" rel="noopener">Get the source code</a>.', $html);
        $this->assertStringNotContainsString('EN | SL', $html);
        $this->assertStringNotContainsString('Buy me a coffee', $html);

        config(['bridge.source_sha' => 'abc1234def']);
        $this->assertStringContainsString('href="https://github.com/AP0L0N/usebridge/tree/abc1234def"', $this->html());
    }

    #[Test]
    public function the_header_has_maries_anchors_every_in_page_link_has_a_target_and_tap_targets_are_44px(): void
    {
        $html = $this->html();
        $xp = $this->dom($html);
        $nav = fn (string $label): array => array_map(
            fn (\DOMElement $a) => [trim($a->textContent), $a->getAttribute('href')],
            iterator_to_array($xp->query('//nav[@aria-label="'.$label.'"]/a')),
        );

        $expected = [['Share a ticket', '#share'], ['Workstreams', '#workstreams'], ['Boards', '#boards'], ['Portfolio', '#portfolio'], ['Connect', '#connect'], ['FAQ', '#faq']];
        $this->assertSame($expected, $nav('Main'));
        $this->assertSame($expected, $nav('Main (menu)'));

        preg_match_all('#href="\#([a-z0-9-]+)"#', $html, $m);
        foreach (array_unique($m[1]) as $anchor) {
            $this->assertSame(1, substr_count($html, 'id="'.$anchor.'"'), "#$anchor has no single target");
        }
        foreach (['share', 'previews', 'workstreams', 'boards', 'portfolio', 'agents', 'connect', 'usage', 'free', 'code', 'faq'] as $id) {
            $this->assertNotNull($xp->query('//section[@id="'.$id.'"]')->item(0), $id);
        }

        $this->assertStringContainsString('<img src="/brand/lockup-dark.svg" alt="bridge">', $html);
        $this->assertSame(2, substr_count($html, '>Sign up free</a>'));
        $this->assertStringContainsString('data-theme-toggle', $html);
        $this->assertStringContainsString('href="/brand/favicon-32.png"', $html);
        $this->assertStringContainsString('href="/brand/favicon-180.png"', $html);
        $this->assertStringContainsString('min-height: var(--tap-min)', file_get_contents(resource_path('css/landing.css')));
    }
}
