<?php

declare(strict_types=1);

namespace Modules\HR\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\HR\Contracts\EmployeeRepositoryInterface;
use Modules\HR\Contracts\RecruitmentCandidateIdentifierRepositoryInterface;
use Modules\HR\Repositories\EloquentEmployeeRepository;
use Modules\HR\Repositories\EloquentRecruitmentCandidateIdentifierRepository;

final class HRServiceProvider extends ServiceProvider
{
    /**
     * Register HR module services.
     */
    public function register(): void
    {
        $this->app->bind(
            EmployeeRepositoryInterface::class,
            EloquentEmployeeRepository::class
        );

        // HR-003 §7.5 — memakai ulang PersonIdentifierCipherInterface
        // milik Core lewat repository ini (lihat binding-nya sendiri di
        // Core\Person\Providers).
        $this->app->bind(
            RecruitmentCandidateIdentifierRepositoryInterface::class,
            EloquentRecruitmentCandidateIdentifierRepository::class,
        );
    }

    /**
     * Bootstrap HR module services.
     */
    public function boot(): void
    {
        /*
         * Register HR API routes.
         */
        Route::prefix('api')
            ->middleware('api')
            ->group(
                base_path(
                    'Modules/HR/Routes/api.php',
                ),
            );

        /*
         * Register HR database migrations.
         */
        $this->loadMigrationsFrom(
            __DIR__ . '/../Database/Migrations'
        );
    }
}
