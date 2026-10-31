<?php

namespace App\Services\Ops;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Throwable;

/**
 * `bridge:rotate-copied-tokens` (docs/MULTI-USER-PLAN.md §11): a staging copy of the live database also holds the live
 * token hashes, so every live plaintext would work there. This gives every token row (all users, all workspaces,
 * expired and blocked ones too) a fresh plaintext, made like Sanctum's (token_prefix + 40 random + crc32b), in place:
 * only `token` (sha256) and `token_suffix` change; id, owner, name, abilities, workspace_id, expires_at, last_used_at
 * stay. The new plaintexts (`id|token`, Sanctum's plainTextToken format) go to a new 0600 file, nowhere else.
 *
 * Refused (refusal()) on APP_ENV production, outside staging without --force-env=staging, and when APP_URL is the live
 * host. The rows are updated and the file written in one transaction: a failed write rolls the rows back.
 */
final class CopiedTokenRotation
{
    public const LIVE_HOST = 'bridge.tilenpoje.eu';

    /** Why this run is refused, or null when it may go ahead. */
    public static function refusal(string $env, ?string $forceEnv, ?string $appUrl, ?string $out): ?string
    {
        if ($env === 'production') {
            return 'APP_ENV is production: refusing.';
        }
        if ($forceEnv !== null && $forceEnv !== 'staging') {
            return '--force-env only accepts "staging".';
        }
        if ($env !== 'staging' && $forceEnv === null) {
            return "APP_ENV is '$env', not staging: refusing (--force-env=staging on a non-production copy).";
        }
        $host = self::host((string) $appUrl);
        if ($host === '') {
            return 'APP_URL has no host: refusing.';
        }
        if ($host === self::LIVE_HOST) {
            return 'APP_URL is the live Bridge ('.self::LIVE_HOST.'): refusing.';
        }
        if (! filled($out)) {
            return '--out=<path> is required.';
        }
        if (file_exists($out) || is_link($out)) {
            return "$out already exists: refusing to overwrite.";
        }
        $dir = dirname($out);
        if (! is_dir($dir) || ! is_writable($dir)) {
            return "$dir is not a writable directory.";
        }

        return null;
    }

    /**
     * @return int rows rotated (or that would be, with $dryRun)
     *
     * @throws RuntimeException the file could not be written (rows rolled back)
     */
    public function rotate(string $out, bool $dryRun): int
    {
        return DB::transaction(function () use ($out, $dryRun) {
            $tokens = PersonalAccessToken::query()->orderBy('id')->lockForUpdate()->get();
            if ($dryRun) {
                return $tokens->count();
            }
            $names = self::envNames($tokens->pluck('name', 'id')->all());
            $suffix = Schema::hasColumn('personal_access_tokens', 'token_suffix');
            $generator = new User;

            $lines = ['# bridge:rotate-copied-tokens '.Carbon::now()->toIso8601String().', '.$tokens->count().' tokens'];
            foreach ($tokens as $token) {
                $plain = $generator->generateTokenString();
                $token->timestamps = false;
                $token->forceFill(['token' => hash('sha256', $plain), ...($suffix ? ['token_suffix' => substr($plain, -4)] : [])])->save();
                $lines[] = $names[$token->id].'='.$token->id.'|'.$plain;
            }
            self::write($out, implode("\n", $lines)."\n");

            return $tokens->count();
        });
    }

    /**
     * Env-safe upper-case names: 'ceo' -> BRIDGE_API_TOKEN, 'agent-janice' -> AGENT_JANICE; _<id> on repeated names.
     *
     * @param  array<int, string>  $namesById
     * @return array<int, string>
     */
    public static function envNames(array $namesById): array
    {
        $base = [];
        foreach ($namesById as $id => $name) {
            $key = $name === 'ceo' ? 'BRIDGE_API_TOKEN' : strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name), '_'));
            $base[$id] = match (true) {
                $key === '' => 'TOKEN',
                ctype_digit($key[0]) => 'TOKEN_'.$key,
                default => $key,
            };
        }
        $counts = array_count_values($base);
        $names = [];
        foreach ($base as $id => $key) {
            $name = $counts[$key] > 1 ? "{$key}_$id" : $key;
            while (in_array($name, $names, true)) {
                $name .= "_$id";
            }
            $names[$id] = $name;
        }

        return $names;
    }

    /** Lower-case host of a URL without a trailing dot or leading "www." ('' when there is none). */
    private static function host(string $url): string
    {
        $host = rtrim(strtolower((string) parse_url(trim($url), PHP_URL_HOST)), '.');

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** New file only ('x'), 0600 before any content; a partial file is removed again. */
    private static function write(string $out, string $content): void
    {
        $umask = umask(0077);
        try {
            $handle = @fopen($out, 'x');
            if ($handle === false) {
                throw new RuntimeException("Could not create $out.");
            }
            try {
                if (! chmod($out, 0600) || fwrite($handle, $content) !== strlen($content) || ! fflush($handle)) {
                    throw new RuntimeException("Could not write $out.");
                }
            } catch (Throwable $e) {
                fclose($handle);
                @unlink($out);
                throw $e;
            }
            fclose($handle);
        } finally {
            umask($umask);
        }
    }
}
