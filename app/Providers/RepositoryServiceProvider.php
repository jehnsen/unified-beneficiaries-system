<?php

declare(strict_types=1);

namespace App\Providers;

use App\Interfaces\BeneficiaryRepositoryInterface;
use App\Interfaces\ClaimRepositoryInterface;
use App\Interfaces\MunicipalityRepositoryInterface;
use App\Interfaces\UserRepositoryInterface;
use App\Interfaces\VerifiedDistinctPairRepositoryInterface;
use App\Repositories\EloquentBeneficiaryRepository;
use App\Repositories\EloquentClaimRepository;
use App\Repositories\EloquentMunicipalityRepository;
use App\Repositories\EloquentUserRepository;
use App\Repositories\EloquentVerifiedDistinctPairRepository;
use Illuminate\Support\ServiceProvider;

/**
 * RepositoryServiceProvider - Binds Repository Interfaces to Implementations.
 *
 * This enforces the Repository Pattern and makes it easy to swap implementations
 * (e.g., switch from Eloquent to a different ORM or data source).
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Bind Beneficiary Repository
        $this->app->bind(
            BeneficiaryRepositoryInterface::class,
            EloquentBeneficiaryRepository::class
        );

        // Bind Claim Repository
        $this->app->bind(
            ClaimRepositoryInterface::class,
            EloquentClaimRepository::class
        );

        // Bind Municipality Repository
        $this->app->bind(
            MunicipalityRepositoryInterface::class,
            EloquentMunicipalityRepository::class
        );

        // Bind User Repository
        $this->app->bind(
            UserRepositoryInterface::class,
            EloquentUserRepository::class
        );

        // Bind VerifiedDistinctPair Repository
        $this->app->bind(
            VerifiedDistinctPairRepositoryInterface::class,
            EloquentVerifiedDistinctPairRepository::class
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
