<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // Use custom Authenticate middleware that returns JSON for unauthenticated API requests
        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
        ]);

        // Disable guest redirects for API - always return JSON 401
        $middleware->redirectGuestsTo(function () {
            return null;
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Always render JSON for API routes and requests that expect JSON
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
