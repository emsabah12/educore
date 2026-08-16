<?php

declare(strict_types=1);

namespace Modules\Dormitory\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Dormitory\Application\Contracts\ResidentPlacementServiceInterface;
use Modules\Dormitory\Application\Services\ResidentPlacementService;
use Modules\Dormitory\Contracts\ResidentEligibilityCheckerInterface;
use Modules\Dormitory\Contracts\ResidentPlacementRepositoryInterface;
use Modules\Dormitory\Contracts\RoomRepositoryInterface;
use Modules\Dormitory\Infrastructure\Eligibility\MembershipResidentEligibilityChecker;
use Modules\Dormitory\Infrastructure\Persistence\Eloquent\EloquentResidentPlacementRepository;
use Modules\Dormitory\Infrastructure\Persistence\Eloquent\EloquentRoomRepository;

final class DormitoryServiceProvider extends ServiceProvider
{
    /**
     * Register Dormitory-owned application services.
     */
    public function register(): void
    {
        $this->app->bind(
            RoomRepositoryInterface::class,
            EloquentRoomRepository::class,
        );

        $this->app->bind(
            ResidentPlacementRepositoryInterface::class,
            EloquentResidentPlacementRepository::class,
        );

        $this->app->bind(
            ResidentEligibilityCheckerInterface::class,
            MembershipResidentEligibilityChecker::class,
        );

        $this->app->bind(
            ResidentPlacementServiceInterface::class,
            ResidentPlacementService::class,
        );
    }

    /**
     * Bootstrap Dormitory-owned infrastructure.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(
            __DIR__.'/../Database/Migrations',
        );
    }
}
