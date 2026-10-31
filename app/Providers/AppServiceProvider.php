<?php

namespace App\Providers;

use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per request in HTTP (WorkspaceBoundary restores it after each request), per run in the console.
        $this->app->singleton(CurrentWorkspace::class);
    }

    public function boot(): void
    {
        Authenticate::redirectUsing(function (Request $request) {
            if ($request->is('api/*', 'mcp') || $request->expectsJson()) {
                return null;
            }

            return route('gate.show');
        });

        // Open sign-up: 5 per IP and hour (relayed decision 1).
        RateLimiter::for('signup', fn (Request $request) => Limit::perHour(5)->by('signup:'.$request->ip())
            ->response(fn () => response()->json(['message' => 'Too many sign-ups from this network. Try again later.'], 429)));

        // MCP: 120 requests per minute per token (docs/MULTI-USER-PLAN.md §4), after auth:sanctum.
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)
            ->by('mcp-token:'.($request->user()?->currentAccessToken()?->getKey() ?? $request->ip()))
            ->response(fn () => response()->json(['message' => 'Too many MCP requests for this token. Try again in a minute.'], 429)));

        // /api: 120 requests per minute per token id (docs/MULTI-USER-PLAN.md §4), after auth:sanctum; a first-party
        // session (Sanctum TransientToken, no id) counts per user.
        RateLimiter::for('api-token', fn (Request $request) => Limit::perMinute(120)
            ->by(($token = $request->user()?->currentAccessToken()) instanceof Model
                ? 'api-token:'.$token->getKey()
                : 'api-user:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response(fn () => response()->json(['message' => 'Too many API requests for this token. Try again in a minute.'], 429)));

        // Cursor CSV upload (POST /usage/cursor-csv): 10 per hour per user, else per IP (gate session).
        RateLimiter::for('cursor-csv', fn (Request $request) => Limit::perHour(10)
            ->by('cursor-csv:'.(Auth::guard('web')->id() ?? $request->ip()))
            ->response(fn () => response()->json(['message' => 'Too many Cursor CSV uploads. Try again later.'], 429)));

        // Reset link for the (future) reset screen; built here so no route has to exist yet (docs/MULTI-USER-PLAN.md §2).
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim((string) config('app.url'), '/')
            .'/reset-password/'.$token.'?email='.urlencode($user->getEmailForPasswordReset()));
    }
}
