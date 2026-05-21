<?php

use App\Console\Commands\AlertStaleFraudChecks;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
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
    ->withSchedule(function (Schedule $schedule) {
        // Alert supervisors every 30 minutes about claims stuck in PENDING_FRAUD_CHECK
        // for more than 1 hour — these represent permanently failed fraud scan jobs
        // that require manual intervention before disbursement can proceed.
        $schedule->command(AlertStaleFraudChecks::class)->everyThirtyMinutes();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Always render JSON for API routes and requests that expect JSON
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
