<?php

namespace Tests\Feature\MultiUser;

use App\Services\Auth\SignupBotCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Turnstile without pre-clearance (docs/MULTI-USER-PLAN.md §0): nothing from Cloudflare when the sign-up page renders,
 * no cookies but the app's own, the script only from the submit path; the server still verifies when a secret is set.
 */
class TurnstileLazyTest extends TestCase
{
    use RefreshDatabase;

    private const CF = 'challenges.cloudflare.com';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::preventStrayRequests();
        config(['turnstile.site_key' => 'site-key-public', 'turnstile.secret_key' => null]);
    }

    #[Test]
    public function the_signup_page_sets_only_the_session_and_xsrf_cookies(): void
    {
        foreach ([[], ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest']] as $headers) {
            $res = $this->withHeaders($headers)->get('/signup')->assertOk();
            $names = array_map(fn ($c) => $c->getName(), $res->headers->getCookies());

            $this->assertNotEmpty($names);
            $this->assertSame([], array_values(array_diff($names, [config('session.cookie'), 'XSRF-TOKEN'])), implode(', ', $names));
        }
    }

    #[Test]
    public function the_signup_html_and_props_reference_no_cloudflare_script(): void
    {
        $html = $this->get('/signup')->assertOk()->getContent();
        $this->assertStringNotContainsString(self::CF, $html);
        $this->assertDoesNotMatchRegularExpression('/<script[^>]+turnstile/i', $html);

        $props = $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->get('/signup')->json('props');
        $this->assertSame('site-key-public', $props['turnstileSiteKey']);
        $this->assertStringNotContainsString(self::CF, json_encode($props));
        $this->assertStringNotContainsString(self::CF, (string) file_get_contents(resource_path('views/app.blade.php')));
    }

    #[Test]
    public function the_script_url_is_only_referenced_from_the_submit_path(): void
    {
        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (str_contains((string) file_get_contents($file->getPathname()), self::CF)) {
                $hits[] = str_replace(resource_path().'/', '', $file->getPathname());
            }
        }
        $this->assertSame(['js/lib/turnstile.ts'], $hits);

        $lib = (string) file_get_contents(resource_path('js/lib/turnstile.ts'));
        $this->assertStringContainsString("'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'", $lib);
        $this->assertStringContainsString("execution: 'execute'", $lib);
        $this->assertStringContainsString("appearance: 'interaction-only'", $lib);
        // The script is added only via turnstileToken() (one definition, one call), never at module load.
        $this->assertSame(2, substr_count($lib, 'loadTurnstile()'));
        $this->assertSame(1, substr_count($lib, 'await loadTurnstile()'));

        // The page calls turnstileToken() once, inside the submit handler, and never in an effect or at render.
        $page = (string) file_get_contents(resource_path('js/Pages/Auth/Signup.tsx'));
        $this->assertSame(1, substr_count($page, 'turnstileToken('));
        $this->assertStringNotContainsString('useEffect', $page);
        $submit = substr($page, strpos($page, 'const submit = async'));
        $submit = substr($submit, 0, strpos($submit, "\n  };\n"));
        $this->assertStringContainsString('await turnstileToken(turnstileSiteKey', $submit);
    }

    #[Test]
    public function the_server_still_verifies_the_token_when_a_secret_is_set(): void
    {
        config(['turnstile.secret_key' => 'secret']);
        Http::fake([self::CF.'/*' => fn (HttpRequest $r) => Http::response(['success' => $r['response'] === 'good'])]);

        $post = function (array $extra) {
            $token = $this->get('/signup')->inertiaProps('formToken');
            $this->travel(5)->seconds();

            return $this->postJson('/signup', [
                'name' => 'Sam Rivera', 'email' => 'sam@example.test', 'password' => 'garden-planner-27',
                SignupBotCheck::HONEYPOT => '', 'form_token' => $token, 'accept_terms' => true, ...$extra,
            ]);
        };

        $post([])->assertStatus(422)->assertJsonValidationErrors('turnstile');
        $post(['cf-turnstile-response' => 'bad'])->assertStatus(422)->assertJsonValidationErrors('turnstile');
        $post(['cf-turnstile-response' => 'good'])->assertCreated();
        Http::assertSent(fn (HttpRequest $r) => $r['secret'] === 'secret' && $r['response'] === 'good');
    }
}
