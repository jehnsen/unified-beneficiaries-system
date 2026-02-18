<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Define authorization gate for system settings management
        // Only Provincial Staff with Admin role can manage settings
        Gate::define('manage-settings', function (User $user) {
            return $user->isProvincialStaff() && $user->isAdmin();
        });

        // Both Provincial and Municipal Staff can approve/reject claims.
        // Without these definitions, the 'can:' middleware throws a 500 instead of 403.
        Gate::define('approve-claims', function (User $user) {
            return $user->isMunicipalStaff() || $user->isProvincialStaff();
        });

        Gate::define('reject-claims', function (User $user) {
            return $user->isMunicipalStaff() || $user->isProvincialStaff();
        });
    }
}
