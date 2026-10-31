<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogoutBlockedUser;
use App\Http\Middleware\WorkspaceBoundary;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [WorkspaceBoundary::class], append: [
            LogoutBlockedUser::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->api(prepend: [WorkspaceBoundary::class]);
        // The usage and Polymarket uploads are decoded only by their validators, after the size/type guard.
        $usageUpload = fn (Request $request) => $request->is('api/usage/days', 'api/polymarket/feed');
        $middleware->trimStrings(except: [$usageUpload]);
        $middleware->convertEmptyStringsToNull(except: [$usageUpload]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'mcp') || $request->expectsJson(),
        );
        // S9: a missing model is a plain 404 in JSON; never leak the model class ("No query results for model [App\\…]").
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($e->getPrevious() instanceof ModelNotFoundException && ($request->is('api/*', 'mcp') || $request->expectsJson())) {
                return response()->json(['message' => 'Not found.'], 404);
            }

            return null;
        });
    })->create();
