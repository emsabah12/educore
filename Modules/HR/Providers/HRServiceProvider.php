<?php

declare(strict_types=1);

namespace Modules\HR\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\HR\Repositories\EloquentEmployeeRepository;
use Modules\HR\Contracts\EmployeeRepositoryInterface;

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
