<?php

declare(strict_types=1);

namespace Modules\Dormitory\Providers;

use Illuminate\Support\ServiceProvider;

final class DormitoryServiceProvider extends ServiceProvider
{
    /**
     * Register Dormitory-owned application services.
     */
    public function register(): void
    {
        // Intentionally empty for the module baseline.
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
