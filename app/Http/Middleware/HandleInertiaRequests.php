<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Legal\LegalPages;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'gateUnlocked' => (bool) $request->session()->get('bridge_gate_unlocked', false),
            // The logged-in user's own account for the top bar (null for guests and AUTH-1 gate sessions). Never a token.
            'auth' => fn () => ['user' => self::user()],
            // Lazy: resolved at render time, after the gate set the workspace (false on the gate page). polymarket: the
            // workspace owner (or the gate session) only. admin: the Admin nav item, only for a logged-in is_admin user
            // (RequireAdmin answers 404 to everyone else).
            'features' => fn () => [
                Workspace::FEATURE_POLYMARKET => app(CurrentWorkspace::class)->hasOwnerFeature(Workspace::FEATURE_POLYMARKET),
                'admin' => self::user()['is_admin'] ?? false,
            ],
            // Site footer (every page): legal links + AGPL source link; the EN | SL switch only on the legal pages.
            'footer' => fn () => LegalPages::footer($request->route()?->defaults['legalPage'] ?? null),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /** @return array{id: int, name: string, email: string, initials: string, is_admin: bool, email_verified: bool}|null */
    private static function user(): ?array
    {
        $user = Auth::guard('web')->user();
        if (! $user instanceof User || $user->isBlocked()) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'initials' => self::initials($user->name),
            'is_admin' => (bool) $user->is_admin,
            'email_verified' => $user->hasVerifiedEmail(),
        ];
    }

    /** "Sam Rivera" -> "SR", "Tilen" -> "T" (avatar in the account menu). */
    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_map(fn (string $w) => mb_substr($w, 0, 1), array_slice($words, 0, 2))));
    }
}
