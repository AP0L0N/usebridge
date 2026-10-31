<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A user's logged-in devices = their rows in the `sessions` table (database session driver, docs/MULTI-USER-PLAN.md
 * §2). Logging a device out deletes its row (a session is a credential, not data). "All devices" also rotates the
 * remember token so "keep me logged in" cookies stop working.
 */
final class DeviceSessions
{
    /** @return list<array{id: string, ip: ?string, user_agent: ?string, last_active: string, current: bool}> */
    public function list(User $user, ?string $currentId = null): array
    {
        return DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($row) => [
                // The raw id is the session cookie value: only a hash leaves the server.
                'id' => hash('sha256', (string) $row->id),
                'ip' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'last_active' => Carbon::createFromTimestamp((int) $row->last_activity)->toIso8601String(),
                'current' => $currentId !== null && hash_equals((string) $row->id, $currentId),
            ])->values()->all();
    }

    /** Every device except the current one. Returns the number of sessions ended. */
    public function logoutOthers(User $user, ?string $currentId): int
    {
        return DB::table('sessions')->where('user_id', $user->id)
            ->when($currentId !== null, fn ($q) => $q->where('id', '!=', $currentId))
            ->delete();
    }

    /** Every device, and every "keep me logged in" cookie. */
    public function logoutAll(User $user): int
    {
        $this->rotateRememberToken($user);

        return DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    public function rotateRememberToken(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }
}
