<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Claim;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });

        // Custom route model binding for fraud-alerts routes
        // This allows provincial staff to access fraud alerts across municipalities
        Route::bind('claim', function (string $value) {
            // Check if we're in fraud-alerts context
            if (request()->is('api/fraud-alerts/*')) {
                // Bypass TenantScope for provincial access to fraud alerts
                // Ensure claim is flagged
                return Claim::withoutGlobalScopes()
                    ->where('uuid', $value)
                    ->where('is_flagged', true)
                    ->firstOrFail();
            }

            // Default: Apply TenantScope (disbursement, intake, claims routes)
            // Laravel automatically applies TenantScope via the model
            return Claim::where('uuid', $value)->firstOrFail();
        });
    }
}
