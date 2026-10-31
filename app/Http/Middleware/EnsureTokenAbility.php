<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token abilities (docs/MULTI-USER-PLAN.md §4), derived from the route: reads need boards:read, writes boards:write,
 * except status updates / project status (status:write), agents (agents:write), usage upload (usage:write) and the
 * Polymarket feed (polymarket:write). '*' and the legacy 'ceo' ability (BRIDGE_API_TOKEN) allow everything.
 */
class EnsureTokenAbility
{
    public const FULL = ['*', 'ceo'];

    public const ABILITIES = ['boards:read', 'boards:write', 'status:write', 'agents:write', 'usage:write', 'polymarket:write'];

    /** route name => ability, for writes outside boards:write */
    private const WRITE_ABILITIES = [
        'api.status-updates.store' => 'status:write',
        'api.status-updates.destroy' => 'status:write',
        'api.projects.status.update' => 'status:write',
        'api.agents.store' => 'agents:write',
        'api.agents.update' => 'agents:write',
        'api.usage.days.store' => 'usage:write',
        'api.polymarket.feed.store' => 'polymarket:write',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $needed = self::abilityFor($request);
        if (self::allows($request->user(), $needed)) {
            return $next($request);
        }

        return response()->json(['message' => self::lacks($needed)], 403);
    }

    /** The token (of $user) has $ability, '*' or the legacy 'ceo'. Also used by the MCP tools. */
    public static function allows(mixed $user, string $ability): bool
    {
        foreach ([...self::FULL, $ability] as $a) {
            if ($user?->tokenCan($a)) {
                return true;
            }
        }

        return false;
    }

    public static function lacks(string $ability): string
    {
        return "This token lacks the '$ability' ability.";
    }

    public static function abilityFor(Request $request): string
    {
        if ($request->isMethodSafe()) {
            return 'boards:read';
        }

        return self::WRITE_ABILITIES[$request->route()?->getName()] ?? 'boards:write';
    }
}
