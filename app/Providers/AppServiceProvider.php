<?php

namespace App\Providers;

use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
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
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return route('gate.show');
        });
    }
}
