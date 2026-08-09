<?php

declare(strict_types=1);

namespace Modules\Auth\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Auth\Authentication\Contracts\AuthenticationRepositoryInterface;
use Modules\Auth\Repositories\AuthenticationRepository;
use Modules\Auth\Services\DeterministicTokenManager;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Auth\Token\Contracts\TokenRevocationStoreInterface;
use Modules\Auth\Token\Persistence\DatabaseTokenRevocationStore;

final class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register application services owned by Auth.
     */
    public function register(): void
    {
        $this->app->singleton(
            AuthenticationRepositoryInterface::class,
            AuthenticationRepository::class,
        );

        $this->app->singleton(
            TokenRevocationStoreInterface::class,
            DatabaseTokenRevocationStore::class,
        );

        $this->app->singleton(
            TokenManagerInterface::class,
            DeterministicTokenManager::class,
        );
    }

    /**
     * Bootstrap Auth-owned infrastructure.
     */
    public function boot(): void
    {
        $this->registerMigrations();
        $this->registerRoutes();
    }

    private function registerMigrations(): void
    {
        $migrationPath = base_path(
            'Modules/Auth/Database/Migrations',
        );

        if (! is_dir($migrationPath)) {
            return;
        }

        $this->loadMigrationsFrom(
            $migrationPath,
        );
    }

    private function registerRoutes(): void
    {
        $routeFilePath = base_path(
            'Modules/Auth/Routes/api.php',
        );

        if (! is_file($routeFilePath)) {
            return;
        }

        Route::prefix('api')
            ->middleware('api')
            ->group($routeFilePath);
    }
}
