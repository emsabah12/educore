<?php

declare(strict_types=1);

namespace Modules\Academic\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Academic\Contracts\GuardianStudentRepositoryInterface;
use Modules\Academic\Contracts\GuardianRepositoryInterface;
use Modules\Academic\Contracts\Repository\AcademicClassRepositoryInterface;
use Modules\Academic\Contracts\Repository\AcademicPeriodRepositoryInterface;
use Modules\Academic\Contracts\Repository\AcademicSubjectRepositoryInterface;
use Modules\Academic\Contracts\StudentRepositoryInterface;
use Modules\Academic\Repositories\EloquentAcademicClassRepository;
use Modules\Academic\Repositories\EloquentAcademicPeriodRepository;
use Modules\Academic\Repositories\EloquentAcademicSubjectRepository;
use Modules\Academic\Repositories\EloquentGuardianRepository;
use Modules\Academic\Repositories\EloquentGuardianStudentRepository;
use Modules\Academic\Repositories\EloquentStudentRepository;

final class AcademicServiceProvider extends ServiceProvider
{
    /**
     * Register Academic module services.
     */
    public function register(): void
    {
        /*
         * Academic Period Repository
         */
        $this->app->singleton(
            AcademicPeriodRepositoryInterface::class,
            EloquentAcademicPeriodRepository::class
        );

        /*
         * Academic Class Repository
         */
        $this->app->singleton(
            AcademicClassRepositoryInterface::class,
            EloquentAcademicClassRepository::class
        );

        /*
         * Academic Subject Repository
         */
        $this->app->singleton(
            AcademicSubjectRepositoryInterface::class,
            EloquentAcademicSubjectRepository::class
        );

        /*
         * Student Repository
         */
        $this->app->singleton(
            StudentRepositoryInterface::class,
            EloquentStudentRepository::class
        );

        /*
         * Guardian Repository
         */
        $this->app->singleton(
            GuardianRepositoryInterface::class,
            EloquentGuardianRepository::class
        );

        /*
         * Guardian-Student Repository
         */
        $this->app->singleton(
            GuardianStudentRepositoryInterface::class,
            EloquentGuardianStudentRepository::class
        );
    }

    /**
     * Bootstrap Academic module services.
     */
    public function boot(): void
    {
        /*
         * Register Academic API routes.
         */
        Route::prefix('api')
            ->middleware('api')
            ->group(
                base_path(
                    'Modules/Academic/Routes/api.php',
                ),
            );

        /*
         * Register Academic database migrations.
         */
        $this->loadMigrationsFrom(
            __DIR__ . '/../Database/Migrations'
        );
    }
}
