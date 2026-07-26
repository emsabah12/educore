<?php

declare(strict_types=1);

namespace Modules\Auth\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Auth\Authentication\Contracts\AuthenticationRepositoryInterface;
use Modules\Auth\Repositories\AuthenticationRepository;
use Modules\Auth\Services\DeterministicTokenManager;
use Modules\Auth\Token\Contracts\TokenManagerInterface;

final class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {

        $this->app->singleton(
            AuthenticationRepositoryInterface::class,
            AuthenticationRepository::class
        );

        $this->app->singleton(
            TokenManagerInterface::class,
            DeterministicTokenManager::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {



        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        $routeFilePath = base_path('Modules/Auth/Routes/api.php');

        if (! is_file($routeFilePath)) {
            return;
        }

        Route::prefix('api')
            ->middleware('api')
            ->group($routeFilePath);
    }
}
