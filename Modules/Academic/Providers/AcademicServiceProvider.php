<?php

namespace Modules\Academic\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Academic\Contracts\Repository\AcademicClassRepositoryInterface;
use Modules\Academic\Repositories\EloquentAcademicClassRepository;
use Modules\Academic\Contracts\Repository\AcademicPeriodRepositoryInterface;
use Modules\Academic\Repositories\EloquentAcademicPeriodRepository;
use Modules\Academic\Contracts\Repository\AcademicSubjectRepositoryInterface;
use Modules\Academic\Repositories\EloquentAcademicSubjectRepository;

class AcademicServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Pendaftaran Binding Dependency Injection (Interface -> Eloquent Repository)
        $this->app->bind(
            AcademicPeriodRepositoryInterface::class,
            EloquentAcademicPeriodRepository::class
        );

        $this->app->bind(
            AcademicClassRepositoryInterface::class,
            EloquentAcademicClassRepository::class
        );

        $this->app->bind(
            AcademicSubjectRepositoryInterface::class,
            EloquentAcademicSubjectRepository::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Memuat migrasi modul agar tersedia secara otomatis saat testing/runtime
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
