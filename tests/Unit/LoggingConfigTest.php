<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Log retention (MUST-BUILD A5): daily files kept 14 days unless .env says otherwise. */
class LoggingConfigTest extends TestCase
{
    private const VARS = ['LOG_CHANNEL', 'LOG_STACK', 'LOG_DAILY_DAYS'];

    /** config/logging.php as it reads with the given env (null = unset). */
    private function logging(array $env): array
    {
        $saved = [];
        foreach (self::VARS as $k) {
            $saved[$k] = [$_ENV[$k] ?? null, $_SERVER[$k] ?? null, getenv($k)];
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($k);
            if (($env[$k] ?? null) !== null) {
                $_ENV[$k] = $_SERVER[$k] = $env[$k];
                putenv("$k={$env[$k]}");
            }
        }
        try {
            return require config_path('logging.php');
        } finally {
            foreach ($saved as $k => [$e, $s, $g]) {
                unset($_ENV[$k], $_SERVER[$k]);
                putenv($k);
                if ($e !== null) {
                    $_ENV[$k] = $e;
                }
                if ($s !== null) {
                    $_SERVER[$k] = $s;
                }
                if ($g !== false) {
                    putenv("$k=$g");
                }
            }
        }
    }

    #[Test]
    public function the_defaults_are_daily_files_kept_14_days(): void
    {
        $c = $this->logging([]);
        $this->assertSame('daily', $c['default']);
        $this->assertSame(['daily'], $c['channels']['stack']['channels']);
        $this->assertSame('daily', $c['channels']['daily']['driver']);
        $this->assertSame(14, $c['channels']['daily']['max_files']);
    }

    #[Test]
    public function env_overrides_still_apply(): void
    {
        $c = $this->logging(['LOG_CHANNEL' => 'stack', 'LOG_STACK' => 'daily,stderr', 'LOG_DAILY_DAYS' => '7']);
        $this->assertSame('stack', $c['default']);
        $this->assertSame(['daily', 'stderr'], $c['channels']['stack']['channels']);
        $this->assertSame(7, $c['channels']['daily']['max_files']);
    }

    #[Test]
    public function the_env_example_sets_daily_and_14_days(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));
        foreach (['LOG_CHANNEL=daily', 'LOG_STACK=daily', 'LOG_DAILY_DAYS=14'] as $line) {
            $this->assertMatchesRegularExpression('/^'.preg_quote($line, '/').'$/m', $example);
        }
    }
}
