<?php

namespace Tests\Feature\Legal;

use App\Models\User;
use App\Support\Legal\LegalPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Legal pages (public, EN + SL, EN | SL switch) and the site footer on every page. The expected strings are the UI copy
 * (v3) with the operator as :entity; the final legal texts are never read here: a temp dir holds synthetic markdown.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    private const EN_LINKS = [['label' => 'Imprint', 'href' => '/imprint'], ['label' => 'Terms', 'href' => '/terms'], ['label' => 'Privacy', 'href' => '/privacy']];

    private const SL_LINKS = [['label' => 'Impresum', 'href' => '/sl/impresum'], ['label' => 'Pogoji', 'href' => '/sl/pogoji'], ['label' => 'Zasebnost', 'href' => '/sl/zasebnost']];

    private const PREVAILS = 'For users in Slovenia, the Slovenian version prevails. · Za uporabnike v Sloveniji prevlada slovenska različica.';

    private ?string $dir = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bridge.legal_entity' => 'Example Studio', 'bridge.source_url' => 'https://github.com/AP0L0N/usebridge', 'bridge.source_sha' => null, 'bridge.legal_dir' => null]);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    public static function pages(): array
    {
        return [
            'terms' => ['/terms', 'en', 'Terms of Use', '/terms', '/sl/pogoji'],
            'privacy' => ['/privacy', 'en', 'Privacy Policy', '/privacy', '/sl/zasebnost'],
            'imprint' => ['/imprint', 'en', 'Imprint', '/imprint', '/sl/impresum'],
            'pogoji' => ['/sl/pogoji', 'sl', 'Pogoji uporabe', '/terms', '/sl/pogoji'],
            'zasebnost' => ['/sl/zasebnost', 'sl', 'Politika zasebnosti', '/privacy', '/sl/zasebnost'],
            'impresum' => ['/sl/impresum', 'sl', 'Impresum', '/imprint', '/sl/impresum'],
        ];
    }

    #[Test]
    #[DataProvider('pages')]
    public function each_legal_page_is_public_with_the_placeholder_the_header_line_and_the_language_switch(string $url, string $locale, string $title, string $en, string $sl): void
    {
        $placeholder = $locale === 'en' ? 'Placeholder — the final text is being prepared.' : 'Začasno besedilo — končno besedilo je v pripravi.';

        $res = $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Legal/Show')
            ->where('locale', $locale)
            ->where('title', $title)
            ->where('prevails', self::PREVAILS)
            ->where('html', fn (string $html) => str_contains($html, $placeholder) && str_contains($html, '<h1>'))
            ->where('footer.locale', $locale)
            ->where('footer.links', $locale === 'en' ? self::EN_LINKS : self::SL_LINKS)
            ->where('footer.switch.en', $en)
            ->where('footer.switch.sl', $sl));
        $this->assertStringContainsString('<html lang="'.$locale.'">', $res->getContent());
        $this->assertGuest();
    }

    #[Test]
    public function the_footer_copy_matches_the_ui_copy_and_the_lang_keys(): void
    {
        $en = $this->get('/terms')->inertiaProps('footer');
        $this->assertSame([
            'locale' => 'en',
            'navLabel' => 'Legal and source code',
            'links' => self::EN_LINKS,
            'source' => ['label' => 'Source code (AGPL-3.0-or-later)', 'href' => 'https://github.com/AP0L0N/usebridge'],
            'copyright' => '© Example Studio · Bridge is free software under AGPL-3.0-or-later. :link.',
            'getSource' => 'Get the source code',
            'switch' => ['label' => 'Language', 'en' => '/terms', 'sl' => '/sl/pogoji'],
        ], $en);
        $this->assertSame(__('legal.footer.copyright', ['entity' => 'Example Studio'], 'en'), $en['copyright']);
        $this->assertSame(__('legal.footer.source', [], 'en'), $en['source']['label']);

        $sl = $this->get('/sl/pogoji')->inertiaProps('footer');
        $this->assertSame('Izvorna koda (AGPL-3.0-or-later)', $sl['source']['label']);
        $this->assertSame('© Example Studio · Bridge je prosto programje pod licenco AGPL-3.0-or-later. :link.', $sl['copyright']);
        $this->assertSame('Prenesite izvorno kodo', $sl['getSource']);
        $this->assertSame(__('legal.footer.copyright', ['entity' => 'Example Studio'], 'sl'), $sl['copyright']);

        // The lang files carry :entity, never an operator name.
        $this->assertSame('© :entity · Bridge is free software under AGPL-3.0-or-later. :link.', __('legal.footer.copyright', [], 'en'));
        config(['bridge.legal_entity' => 'Other Co']);
        $this->assertStringStartsWith('© Other Co · ', $this->get('/login')->inertiaProps('footer.copyright'));
    }

    #[Test]
    public function the_footer_is_on_login_signup_reset_and_logged_in_pages_without_the_switch(): void
    {
        $check = fn (Assert $p) => $p->where('footer.locale', 'en')->where('footer.links', self::EN_LINKS)
            ->where('footer.source.href', 'https://github.com/AP0L0N/usebridge')->where('footer.switch', null);

        $this->get('/login')->assertOk()->assertInertia(fn (Assert $p) => $check($p->component('Auth/Login')));
        $this->get('/signup')->assertOk()->assertInertia(fn (Assert $p) => $check($p->component('Auth/Signup')));
        $this->get('/forgot-password')->assertOk()->assertInertia(fn (Assert $p) => $check($p->component('Auth/ForgotPassword')));

        // Logged in (the backfilled owner, BRIDGE_OWNER_EMAIL in phpunit.xml): the app shell and onboarding.
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->actingAs($owner)->get('/')->assertOk()->assertInertia(fn (Assert $p) => $check($p->component('Portfolio')->where('auth.user.id', $owner->id)));
        $this->actingAs($owner)->get('/welcome')->assertOk()->assertInertia(fn (Assert $p) => $check($p->component('Onboarding')));
    }

    #[Test]
    public function the_source_link_points_at_the_deployed_commit_when_known(): void
    {
        config(['bridge.source_sha' => 'ABCDEF1234567']);
        $this->assertSame('https://github.com/AP0L0N/usebridge/tree/abcdef1234567', $this->get('/login')->inertiaProps('footer.source.href'));

        config(['bridge.source_sha' => 'not-a-sha; rm -rf']);
        $this->assertSame('https://github.com/AP0L0N/usebridge', LegalPages::sourceUrl());

        config(['bridge.source_sha' => null]);
        $file = base_path('.bridge-deploy-sha');
        $this->assertFileDoesNotExist($file);
        try {
            file_put_contents($file, str_repeat('a1', 20)."\n");
            $this->assertSame('https://github.com/AP0L0N/usebridge/tree/'.str_repeat('a1', 20), LegalPages::sourceUrl());
            file_put_contents($file, 'abc');
            $this->assertSame('https://github.com/AP0L0N/usebridge', LegalPages::sourceUrl());
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function final_texts_come_from_the_legal_dir_with_html_comments_scripts_and_unsafe_links_stripped(): void
    {
        $this->dir = sys_get_temp_dir().'/bridge-legal-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/terms.en.md', "<!--\nINTERNAL DRAFT NOTE: not for publishing\n-->\n\n# Terms of Use – Example\n\nHello <script>alert('x')</script> world. [Imprint](/imprint) [bad](javascript:alert(1))\n\n<div onclick=\"steal()\">raw block</div>\n\n| a | b |\n|---|---|\n| 1 | 2 |\n");
        file_put_contents($this->dir.'/pogoji.sl.md', "# Pogoji – primer\n\nBesedilo.\n");
        config(['bridge.legal_dir' => $this->dir]);

        $html = $this->get('/terms')->assertOk()->inertiaProps('html');
        $this->assertStringContainsString('<h1>Terms of Use – Example</h1>', $html);
        $this->assertStringContainsString('<a href="/imprint">Imprint</a>', $html);
        $this->assertStringContainsString('<table>', $html);
        foreach (['INTERNAL DRAFT NOTE', '<!--', '<script', 'javascript:', 'onclick', 'steal()'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
        $this->assertStringContainsString('<h1>Pogoji – primer</h1>', $this->get('/sl/pogoji')->inertiaProps('html'));
        // A file missing from the dir falls back to the placeholder.
        $this->assertStringContainsString('Placeholder — the final text is being prepared.', $this->get('/privacy')->inertiaProps('html'));
    }

    /** The footer as SiteFooter.tsx renders it: links joined by " · ", then " · EN | SL" on legal pages; the © line with the link text. */
    private function renderedFooter(array $f): array
    {
        $line = implode(' · ', [...array_column($f['links'], 'label'), $f['source']['label']]).($f['switch'] ? ' · EN | SL' : '');

        return [$line, str_replace(':link', $f['getSource'], $f['copyright'])];
    }

    #[Test]
    public function the_rendered_footer_lines_match_ui_copy_exactly_with_only_entity_as_placeholder(): void
    {
        config(['bridge.legal_entity' => ':entity']);
        // UI-COPY.md (v3), with the operator name replaced by :entity and the source URL left to the href.
        $this->assertSame([
            'Imprint · Terms · Privacy · Source code (AGPL-3.0-or-later) · EN | SL',
            '© :entity · Bridge is free software under AGPL-3.0-or-later. Get the source code.',
        ], $this->renderedFooter($this->get('/terms')->inertiaProps('footer')));
        $this->assertSame([
            'Impresum · Pogoji · Zasebnost · Izvorna koda (AGPL-3.0-or-later) · EN | SL',
            '© :entity · Bridge je prosto programje pod licenco AGPL-3.0-or-later. Prenesite izvorno kodo.',
        ], $this->renderedFooter($this->get('/sl/pogoji')->inertiaProps('footer')));
        // Other pages: same line without the switch.
        $this->assertSame('Imprint · Terms · Privacy · Source code (AGPL-3.0-or-later)', $this->renderedFooter($this->get('/signup')->inertiaProps('footer'))[0]);

        // The component joins with exactly these separators.
        $tsx = File::get(resource_path('js/Components/SiteFooter.tsx'));
        $this->assertStringContainsString('aria-hidden="true"> · </span>', $tsx);
        $this->assertStringContainsString('<span aria-hidden="true"> | </span>', $tsx);
    }
}
