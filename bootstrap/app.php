<?php

use App\Exceptions\SchemaValidationException;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsurePluginEnabled;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureUserHasOrganization;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'role' => CheckRole::class,
            'organization' => EnsureUserHasOrganization::class,
            'plugin' => EnsurePluginEnabled::class,
            'super-admin' => EnsureSuperAdmin::class,
        ]);

        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Layer schema violations: structured 422 for APIs, session errors for web/Inertia.
        $exceptions->render(function (SchemaValidationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson() && $request->hasSession()) {
                return back()->withInput()->withErrors($e->errors);
            }

            return response()->json([
                'error' => $e->getMessage(),
                'errors' => $e->errors,
            ], 422);
        });
    })->create();
