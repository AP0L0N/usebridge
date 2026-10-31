<?php

namespace Tests\Feature\MultiUser;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Turnstile without pre-clearance (MUST-BUILD A9): the sign-up page loads nothing from Cloudflare and sets no cookie
 * but the app's own; the script is fetched only from the submit handler (resources/js/lib/turnstile.ts).
 */
class TurnstileLazyTest extends TestCase
{
    use RefreshDatabase;

    private const CF = 'challenges.cloudflare.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['turnstile.site_key' => 'site-key-test', 'turnstile.secret_key' => null]);
    }

    #[Test]
    public function the_sign_up_page_sets_only_the_session_and_xsrf_cookies_and_has_no_cloudflare_script(): void
    {
        $res = $this->get('/signup')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Auth/Signup')->where('turnstileSiteKey', 'site-key-test'));

        $names = array_map(fn ($c) => $c->getName(), $res->headers->getCookies());
        sort($names);
        $expected = [config('session.cookie'), 'XSRF-TOKEN'];
        sort($expected);
        $this->assertSame($expected, $names);
        $this->assertNotContains('cf_clearance', $names);

        $this->assertStringNotContainsString(self::CF, $res->getContent());
        $this->assertStringNotContainsString(self::CF, json_encode($res->inertiaProps()));
    }

    #[Test]
    public function the_script_url_is_only_referenced_from_the_submit_path(): void
    {
        $hits = [];
        foreach (array_merge(File::allFiles(resource_path('js')), File::allFiles(resource_path('views'))) as $file) {
            if (str_contains($file->getContents(), self::CF)) {
                $hits[] = str_replace(resource_path().'/', '', $file->getPathname());
            }
        }
        $this->assertSame(['js/lib/turnstile.ts'], $hits);

        $lib = File::get(resource_path('js/lib/turnstile.ts'));
        $this->assertStringContainsString("execution: 'execute'", $lib);
        $this->assertStringContainsString("appearance: 'interaction-only'", $lib);
        $this->assertStringNotContainsString('clearance', strtolower(preg_replace('#^\s*(/\*\*|\*).*$#m', '', $lib)));

        // Only Signup.tsx imports it, and calls it inside the submit handler (not in an effect or at render).
        $importers = [];
        foreach (File::allFiles(resource_path('js')) as $file) {
            if (preg_match('#from [\'"][./]+(lib/)?turnstile[\'"]#', $file->getContents())) {
                $importers[] = $file->getFilename();
            }
        }
        $this->assertSame(['Signup.tsx'], $importers);
        $signup = File::get(resource_path('js/Pages/Auth/Signup.tsx'));
        $this->assertStringNotContainsString('useEffect', $signup);
        $submit = substr($signup, strpos($signup, 'const submit = async'));
        $submit = substr($submit, 0, strpos($submit, "\n  };\n"));
        $this->assertSame(1, substr_count($signup, 'turnstileToken('));
        $this->assertStringContainsString('turnstileToken(', $submit);
    }
}
