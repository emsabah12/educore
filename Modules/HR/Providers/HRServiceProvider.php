<?php

declare(strict_types=1);

namespace Modules\HR\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Repositories\EloquentEmployeeRepository;
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
        $this->loadRoutesFrom(
            base_path('Modules/HR/Routes/api.php')
        );

        $this->loadMigrationsFrom(
            __DIR__ . '/../Database/Migrations'
        );
    }
}
