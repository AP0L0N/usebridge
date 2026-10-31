<?php

namespace Tests\Feature;

use App\Services\Auth\AccountService;
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

    #[Test]
    public function a_guest_sees_the_landing_page_with_its_key_sections_and_footer(): void
    {
        $response = $this->get('/')->assertOk()->assertViewIs('landing');

        $response->assertSeeText([
            'One board for every project your AI bots run.',
            'Your bots do the work. Their updates end up everywhere.',
            'Three steps, then the board keeps itself current.',
            'Connect a bot in three steps.',
            'Free for everyone. No plans, no trial, no card.',
            'Questions before you connect a bot',
            'Give your bots one board to report to.',
            '© LANTIS (Tilen Poje s.p.)',
            'Imprint',
            'Source code (AGPL-3.0-or-later)',
        ]);
        $response->assertSee('href="https://github.com/AP0L0N/usebridge"', false);
        $response->assertDontSee('EN | SL');
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
    public function the_landing_page_has_no_polymarket_text_no_exclamation_marks_and_no_external_scripts(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsStringIgnoringCase('polymarket', $html);
        $this->assertDoesNotMatchRegularExpression('/<script[^>]+src=["\']?(https?:)?\/\//i', $html);
        $this->assertStringNotContainsString('og:image', $html);
        $this->assertStringNotContainsString('!', strip_tags(preg_replace('/<!DOCTYPE[^>]*>/i', '', $html)));
    }

    #[Test]
    public function the_hero_trust_line_is_final_and_unlinked(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertSame(2, substr_count($html, 'Free · No card needed · Open source (AGPL-3.0)'));
        $this->assertStringNotContainsString('on GitHub</p>', $html);
        $this->assertMatchesRegularExpression('#<p class="microcopy">Free · No card needed · Open source \(AGPL-3\.0\)</p>#u', $html);
        $this->assertSame('Free · No card needed · Open source (AGPL-3.0)', __('landing.trust_line'));
    }

    #[Test]
    public function the_hero_animation_is_mounted_and_loaded_from_our_own_origin(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('data-bridge-hero', $html);
        $this->assertStringContainsString('href="/landing/hero/bridge-hero.css"', $html);
        $this->assertStringContainsString('src="/landing/hero/bridge-hero.js"', $html);
        $this->assertFileExists(public_path('landing/hero/bridge-hero.js'));
        $this->assertFileDoesNotExist(public_path('landing/hero/bridge-hero.html'));

        preg_match_all('/\b(?:src|href)=["\']?(https?:)?\/\/([^\/"\'\s>]+)/i', $html, $m);
        foreach ($m[2] as $host) {
            $this->assertContains($host, ['github.com', parse_url(url('/'), PHP_URL_HOST).(parse_url(url('/'), PHP_URL_PORT) ? ':'.parse_url(url('/'), PHP_URL_PORT) : '')], 'external reference to '.$host);
        }
        $this->assertStringNotContainsString('@font-face', file_get_contents(public_path('landing/hero/bridge-hero.css')));
    }

    #[Test]
    public function every_placeholder_is_marked(): void
    {
        $html = $this->get('/')->getContent();

        foreach (['origin-story', 'why-free', 'no-ads', 'coffee-link', 'shutdown-notice', 'usage-line'] as $key) {
            $this->assertStringContainsString('data-placeholder="'.$key.'"', $html, $key);
        }
        $this->assertStringContainsString('data-slot="hero-animation"', $html);
    }

    #[Test]
    public function a_logged_in_user_still_gets_the_portfolio(): void
    {
        Notification::fake();
        $user = app(AccountService::class)->register('Ada Acme', 'ada@example.test', 'acme-studio-planner-27')->fresh();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portfolio'));
    }

    #[Test]
    public function an_unlocked_gate_session_still_gets_the_portfolio(): void
    {
        $this->seed(\Database\Seeders\BridgeSeeder::class);

        $this->gate()->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portfolio'));
    }

    #[Test]
    public function guarded_pages_still_redirect_guests_to_login(): void
    {
        $this->get('/usage')->assertRedirect(route('login'));
        $this->get('/account')->assertRedirect(route('login'));
    }
}
