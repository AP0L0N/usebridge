<?php

namespace Tests\Feature\MultiUser;

use App\Models\User;
use App\Services\Ops\StagingOnlyGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** bridge:verify-email: staging-only (StagingOnlyGuard, as bridge:rotate-copied-tokens); prints the user id only. */
class VerifyEmailCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $alex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alex = User::factory()->unverified()->create(['email' => 'alex@example.test']);
        config(['app.url' => 'https://bridge-staging.example.test']);
    }

    /** @return array{int, string} */
    private function verify(array $options): array
    {
        $code = Artisan::call('bridge:verify-email', $options);

        return [$code, Artisan::output()];
    }

    private function assertNothingSecret(string $output): void
    {
        $this->assertStringNotContainsString('@', $output);
        $this->assertStringNotContainsString('alex', strtolower($output));
        $this->assertDoesNotMatchRegularExpression('#https?://|signature=|/email/verify|[0-9a-f]{32,}#i', $output);
    }

    #[Test]
    public function it_verifies_on_staging_and_with_force_env_on_a_local_copy(): void
    {
        $this->app['env'] = 'staging';
        [$code, $output] = $this->verify(['--email' => 'Alex@Example.test']);
        $this->assertSame(0, $code, $output);
        $this->assertSame($this->alex->id.' verified', trim($output));
        $this->assertNothingSecret($output);
        $this->assertTrue($this->alex->fresh()->hasVerifiedEmail());

        // Already verified: no change, the same output.
        $at = $this->alex->fresh()->email_verified_at;
        $this->travel(1)->hours();
        [$code, $again] = $this->verify(['--email' => 'alex@example.test']);
        $this->assertSame([0, $output], [$code, $again]);
        $this->assertTrue($at->equalTo($this->alex->fresh()->email_verified_at));

        $robin = User::factory()->unverified()->create(['email' => 'robin@example.test']);
        $this->app['env'] = 'local';
        [$code, $output] = $this->verify(['--email' => 'robin@example.test', '--force-env' => 'staging']);
        $this->assertSame([0, $robin->id.' verified'], [$code, trim($output)]);
        $this->assertTrue($robin->fresh()->hasVerifiedEmail());
    }

    #[Test]
    public function dry_run_changes_nothing(): void
    {
        $this->app['env'] = 'staging';
        [$code, $output] = $this->verify(['--email' => 'alex@example.test', '--dry-run' => true]);
        $this->assertSame([0, $this->alex->id.' would verify'], [$code, trim($output)]);
        $this->assertNothingSecret($output);
        $this->assertFalse($this->alex->fresh()->hasVerifiedEmail());
    }

    #[Test]
    public function it_refuses_production_local_without_force_and_the_live_host(): void
    {
        $refused = function (array $options, string $message) {
            [$code, $output] = $this->verify(['--email' => 'alex@example.test', ...$options]);
            $this->assertSame(1, $code, $output);
            $this->assertStringContainsString($message, $output);
            $this->assertNothingSecret($output);
            $this->assertFalse($this->alex->fresh()->hasVerifiedEmail());
        };

        $this->app['env'] = 'production';
        $refused([], 'production');
        $refused(['--force-env' => 'staging'], 'production');
        $this->app['env'] = 'local';
        $refused([], 'not staging');
        $refused(['--force-env' => 'local'], 'only accepts');
        $this->app['env'] = 'staging';
        config(['app.url' => 'https://'.StagingOnlyGuard::LIVE_HOST]);
        $refused([], 'live Bridge');
        config(['app.url' => 'https://www.'.strtoupper(StagingOnlyGuard::LIVE_HOST).'/']);
        $refused([], 'live Bridge');
        config(['app.url' => '']);
        $refused([], 'no host');
    }

    #[Test]
    public function an_unknown_or_missing_email_fails_without_echoing_it(): void
    {
        $this->app['env'] = 'staging';
        foreach ([['--email' => 'nobody@example.test'], []] as $options) {
            [$code, $output] = $this->verify($options);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('No user with that email.', $output);
            $this->assertStringNotContainsString('nobody', $output);
        }
        $this->assertFalse($this->alex->fresh()->hasVerifiedEmail());
    }
}
