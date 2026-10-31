<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * AUTH-1 shared workspace password gate (transitional; docs/MULTI-USER-PLAN.md §2). Fails closed: without a configured
 * password every attempt is rejected, and with bridge.gate_enabled false the gate is retired (404, and a session that
 * was unlocked before unlocks nothing). Every reader of the gate session goes through unlocked().
 */
final class WorkspaceGate
{
    public const SESSION_KEY = 'bridge_gate_unlocked';

    public static function enabled(): bool
    {
        return (bool) config('bridge.gate_enabled', true);
    }

    /** Enabled and a non-empty password configured. */
    public static function configured(): bool
    {
        return self::enabled() && self::password() !== '';
    }

    /** Constant-time compare, only ever against a non-empty configured password. */
    public static function accepts(string $given): bool
    {
        $expected = self::password();

        return self::enabled() && $expected !== '' && hash_equals($expected, $given);
    }

    public static function unlocked(Request $request): bool
    {
        return self::enabled() && $request->hasSession() && $request->session()->get(self::SESSION_KEY) === true;
    }

    private static function password(): string
    {
        return (string) config('bridge.gate_password');
    }
}
