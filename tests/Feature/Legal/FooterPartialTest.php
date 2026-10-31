<?php

namespace Tests\Feature\Legal;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** resources/views/partials/footer.blade.php: the Blade twin of SiteFooter (UI-COPY footer, same lang keys). */
class FooterPartialTest extends TestCase
{
    private const SOURCE = 'https://github.com/AP0L0N/usebridge';

    protected function setUp(): void
    {
        parent::setUp();
        config(['bridge.legal_entity' => 'Example Operator d.o.o.', 'bridge.source_url' => self::SOURCE, 'bridge.source_sha' => null]);
    }

    private function render(array $data = []): string
    {
        return view('partials.footer', $data)->render();
    }

    /** The visible text, as a reader sees it (tags dropped, whitespace collapsed). */
    private static function text(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }

    #[Test]
    public function it_renders_the_exact_footer_lines(): void
    {
        $html = $this->render();

        $this->assertSame(
            'Imprint · Terms · Privacy · Source code (AGPL-3.0-or-later) '
            .'© Example Operator d.o.o. · Bridge is free software under AGPL-3.0-or-later. Get the source code.',
            self::text($html),
        );
        $this->assertStringContainsString('<a href="/imprint">Imprint</a>', $html);
        $this->assertStringContainsString('<a href="/terms">Terms</a>', $html);
        $this->assertStringContainsString('<a href="/privacy">Privacy</a>', $html);
        $this->assertStringContainsString('<a href="'.self::SOURCE.'" rel="noopener">Source code (AGPL-3.0-or-later)</a>', $html);
        $this->assertStringContainsString('<a href="'.self::SOURCE.'" rel="noopener">Get the source code</a>.', $html);
        // EN | SL only on the legal pages.
        $this->assertStringNotContainsString('sfoot-lang', $html);
    }

    #[Test]
    public function the_source_link_points_at_the_deployed_commit_when_known(): void
    {
        config(['bridge.source_sha' => 'ABCDEF1234567']);
        $html = $this->render();

        $this->assertSame(2, substr_count($html, 'href="'.self::SOURCE.'/tree/abcdef1234567"'));
    }

    #[Test]
    public function legal_pages_get_the_language_switch_and_their_language(): void
    {
        $en = $this->render(['legalPage' => 'terms']);
        $this->assertStringEndsWith('Source code (AGPL-3.0-or-later) · EN | SL © Example Operator d.o.o. · Bridge is free software under AGPL-3.0-or-later. Get the source code.', self::text($en));
        $this->assertStringContainsString('<a href="/terms" hreflang="en" lang="en" aria-current="page">EN</a>', $en);
        $this->assertStringContainsString('<a href="/sl/pogoji" hreflang="sl" lang="sl">SL</a>', $en);

        $sl = $this->render(['legalPage' => 'pogoji']);
        $this->assertSame(
            'Impresum · Pogoji · Zasebnost · Izvorna koda (AGPL-3.0-or-later) · EN | SL '
            .'© Example Operator d.o.o. · Bridge je prosto programje pod licenco AGPL-3.0-or-later. Prenesite izvorno kodo.',
            self::text($sl),
        );
        $this->assertStringContainsString('lang="sl"', $sl);
    }

    #[Test]
    public function the_entity_is_escaped(): void
    {
        config(['bridge.legal_entity' => '<script>x</script>']);

        $this->assertStringNotContainsString('<script>', $this->render());
    }

    #[Test]
    public function it_uses_the_same_lang_keys_as_the_inertia_footer(): void
    {
        $blade = (string) file_get_contents(resource_path('views/partials/footer.blade.php'));
        $this->assertStringContainsString('LegalPages::footer(', $blade);
        $this->assertStringContainsString('className="sfoot"', (string) file_get_contents(resource_path('js/Components/SiteFooter.tsx')));
        $this->assertStringContainsString('class="sfoot"', $blade);
    }
}
