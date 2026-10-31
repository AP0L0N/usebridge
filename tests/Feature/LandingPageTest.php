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

/** Logged-out landing page at `/`; users and unlocked gate sessions still get the Portfolio. */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;
    use TicketHelpers;

    private const TRUST = 'Free · No card needed · Open source (AGPL-3.0)';

    private const PENDING = [
        'origin-story' => 'I built Bridge to run my own team of AI bots.',
        'why-free' => 'I built Bridge to run my own AI bots and it costs me little to open it up.',
        'no-ads' => 'No ads. We don\'t sell your data. No paid tier is planned.',
        'coffee-link' => 'Buy me a coffee link and name',
        'why-free-faq' => 'I built it for my own bots and opened it up because it costs little to run.',
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

    #[Test]
    public function a_guest_sees_the_landing_page_with_its_key_headings(): void
    {
        $this->get('/')->assertOk()->assertViewIs('landing')->assertSeeText([
            'One board for every project your AI bots run.',
            'Your bots do the work. Their updates end up everywhere.',
            'Three steps, then the board keeps itself current.',
            'Every project, ticket and status in one place.',
            'Connect a bot in three steps.',
            'Free for everyone. No plans, no trial, no card.',
            'Read the code that runs your boards.',
            'If Bridge saves you time, buy me a coffee.',
            'Questions before you connect a bot',
            'Give your bots one board to report to.',
        ]);
        $this->assertSame('/', route('portfolio', [], false));
    }

    #[Test]
    public function the_landing_page_links_to_signup_and_login(): void
    {
        $this->get('/')
            ->assertSee('href="'.route('signup.show').'"', false)
            ->assertSee('href="'.route('login.show').'"', false);
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
    public function an_unlocked_gate_session_still_gets_the_portfolio(): void
    {
        $this->seed(BridgeSeeder::class);

        $this->gate()->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portfolio'));
    }

    #[Test]
    public function guarded_pages_still_redirect_guests_to_login(): void
    {
        $this->get('/usage')->assertRedirect(route('login'));
        $this->get('/account')->assertRedirect(route('login'));
    }

    #[Test]
    public function there_is_no_polymarket_text_no_exclamation_mark_and_no_external_host_but_github(): void
    {
        $html = $this->html();

        $this->assertStringNotContainsStringIgnoringCase('polymarket', $html);
        $this->assertStringNotContainsStringIgnoringCase('polymarket', file_get_contents(resource_path('css/landing.css')));
        $this->assertDoesNotMatchRegularExpression('/<script[^>]+src=["\']?(https?:)?\/\//i', $html);
        $this->assertStringNotContainsString('og:image', $html);
        $this->assertStringNotContainsString('!', strip_tags(preg_replace('/<!(DOCTYPE|--)[^>]*>/i', '', $html)));
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
        $this->assertStringNotContainsString('on GitHub</p>', $html);
        $this->assertStringNotContainsString('Changelog', $html);
    }

    #[Test]
    public function the_hero_animation_is_mounted_on_the_right_and_loaded_from_our_own_origin(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('data-bridge-hero', $html);
        $this->assertStringContainsString('data-slot="hero-animation"', $html);
        $this->assertStringContainsString('href="/landing/hero/bridge-hero.css"', $html);
        $this->assertStringContainsString('src="/landing/hero/bridge-hero.js"', $html);
        $this->assertFileDoesNotExist(public_path('landing/hero/bridge-hero.html'));
        $this->assertStringNotContainsString('@font-face', file_get_contents(public_path('landing/hero/bridge-hero.css')));

        // Copy block comes before the slot inside the same grid (copy left, stage right).
        $this->assertLessThan(strpos($html, 'id="hero-animation"'), strpos($html, 'id="hero-title"'));
        $this->assertStringContainsString('grid-template-columns: minmax(0, 472fr) minmax(0, 592fr)', file_get_contents(resource_path('css/landing.css')));
    }

    #[Test]
    public function the_pending_blocks_are_absent_by_default_and_the_layout_is_still_complete(): void
    {
        $html = $this->html();

        $this->assertStringNotContainsString('data-placeholder', $html);
        $this->assertStringNotContainsString('PLACEHOLDER', $html);
        foreach (self::PENDING as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Buy me a coffee</', str_replace('buy me a coffee.', '', $html));
        $this->assertStringContainsString('cards cards--2', $html);
        $this->assertStringContainsString('you\'ll get at least 30 days\' notice by email.', $html);
        $this->assertStringContainsString('Automatic usage reporting from your bots is coming soon.', $html);
        $this->assertStringNotContainsString('TBD', $html);
    }

    #[Test]
    public function the_pending_blocks_render_when_the_flag_is_on(): void
    {
        config(['bridge.landing_pending' => true]);
        $html = $this->html();

        foreach (array_keys(self::PENDING) as $key) {
            $this->assertStringContainsString('data-placeholder="'.$key.'"', $html, $key);
        }
        foreach (self::PENDING as $text) {
            $this->assertStringContainsString(str_replace("'", '&#039;', $text), str_replace('&#39;', '&#039;', $html));
        }
        $this->assertStringContainsString('PLACEHOLDER · origin story · Tilen to approve, do not ship', $html);
        $this->assertStringNotContainsString('cards cards--2', $html);
    }

    #[Test]
    public function lime_is_only_used_by_the_hero_cta_and_the_waiting_highlights(): void
    {
        $xp = $this->dom($this->html());

        // Every element with the signal button class is the hero primary CTA, exactly once.
        $signal = $xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " btn--signal ")]');
        $this->assertSame(1, $signal->length);
        $this->assertSame('Create a free account', trim($signal->item(0)->textContent));
        $this->assertNotNull($xp->query('//section[@id="hero"]//a[contains(@class,"btn--signal")]')->item(0));

        // Waiting highlights: only chips/segments/dots/columns that say "Waiting on you" or a "for you" count.
        foreach ($xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " is-waiting ")]') as $el) {
            $text = trim($el->textContent);
            $this->assertTrue(
                $text === '' || str_contains($text, 'Waiting on you') || str_contains($text, 'for you') || $el->nodeName === 'i' || $el->nodeName === 'span',
                'unexpected waiting highlight: '.$text
            );
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
            if (str_contains($body, 'var(--signal)') || str_contains($body, 'var(--bh-lime)')) {
                $uses++;
                $this->assertMatchesRegularExpression('/\.btn--signal|\.is-waiting/', $selector, 'lime outside whitelist: '.trim($selector));
            }
        }
        $this->assertGreaterThan(0, $uses);
    }

    #[Test]
    public function the_footer_renders_the_shared_legal_copy_and_the_source_link(): void
    {
        config(['bridge.legal_entity' => 'Example Studio', 'bridge.source_url' => 'https://github.com/AP0L0N/usebridge', 'bridge.source_sha' => null]);
        $html = $this->html();
        $footer = LegalPages::footer(null);

        foreach ($footer['links'] as $link) {
            $this->assertStringContainsString('<a href="'.$link['href'].'">'.$link['label'].'</a>', $html);
        }
        $this->assertSame(['/imprint', '/terms', '/privacy'], array_column($footer['links'], 'href'));
        $this->assertStringContainsString('<a href="https://github.com/AP0L0N/usebridge" rel="noopener">Source code (AGPL-3.0-or-later)</a>', $html);
        $this->assertStringContainsString('© Example Studio · Bridge is free software under AGPL-3.0-or-later. <a href="https://github.com/AP0L0N/usebridge" rel="noopener">Get the source code</a>.', $html);
        $this->assertStringNotContainsString('EN | SL', $html);
        $this->assertStringContainsString('mailto:support@usebridge.si', $html);

        config(['bridge.source_sha' => 'abc1234def']);
        $this->assertStringContainsString('href="https://github.com/AP0L0N/usebridge/tree/abc1234def"', $this->html());
    }

    #[Test]
    public function the_header_has_the_marketing_anchors_and_every_tap_target_is_44px_by_css(): void
    {
        $html = $this->html();

        foreach (['#how', '#connect', '#free', '#code', '#faq'] as $anchor) {
            $this->assertStringContainsString('href="'.$anchor.'"', $html);
            $this->assertStringContainsString('id="'.substr($anchor, 1).'"', $html);
        }
        $this->assertStringContainsString('<img src="/brand/lockup-dark.svg" alt="bridge">', $html);
        $this->assertStringContainsString('Sign up free', $html);
        $this->assertStringContainsString('href="/brand/favicon-32.png"', $html);
        $this->assertStringContainsString('href="/brand/favicon-180.png"', $html);
        $this->assertSame(7, substr_count($html, '<details'));
        $this->assertStringContainsString('min-height: var(--tap-min)', file_get_contents(resource_path('css/landing.css')));
    }
}
