<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register authentication-related application services.
     *
     * Authentication menggunakan Eloquent User Provider bawaan Laravel.
     *
     * User adalah Global Identity dan tidak memiliki direct tenant coupling.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap authentication-related services.
     */
    public function boot(): void
    {
        $this->registerRoutes();
    }

    /**
     * Register authentication module routes.
     */
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
