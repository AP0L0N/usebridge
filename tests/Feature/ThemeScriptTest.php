<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The inline theme script (partials/theme-script) runs in <head> before any stylesheet, on the app and the landing layout. */
class ThemeScriptTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function layouts(): array
    {
        return ['app layout (login)' => ['/login'], 'landing layout' => ['/']];
    }

    #[Test]
    #[DataProvider('layouts')]
    public function the_theme_is_set_on_html_before_any_stylesheet(string $path): void
    {
        $html = $this->get($path)->assertOk()->getContent();
        $head = substr($html, 0, (int) strpos($html, '</head>'));

        $script = strpos($head, "localStorage.getItem('bridge-theme')");
        $this->assertNotFalse($script, 'theme script missing from <head>');
        $this->assertStringContainsString('prefers-color-scheme: dark', $head);
        $this->assertStringContainsString("d.setAttribute('data-theme',t);d.style.colorScheme=t", $head);

        foreach (['<link rel="stylesheet"', '<link rel="preload" as="style"', '<script type="module"'] as $asset) {
            $at = strpos($head, $asset);
            if ($at !== false) {
                $this->assertLessThan($at, $script, "theme script must come before {$asset}");
            }
        }
    }
}
