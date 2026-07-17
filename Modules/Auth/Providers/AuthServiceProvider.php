<?php

declare(strict_types=1);

namespace Modules\Auth\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\Core\Contracts\Auth\AuthenticationRepositoryInterface;
use Modules\Core\Contracts\Auth\TokenManagerInterface;

final class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bindings akan diimplementasikan di langkah berikutnya saat membuat engine konkret
        // Mengikat Kontrak Inti Kernel ke Implementasi Modul Auth
        $this->app->singleton(
            \Modules\Core\Contracts\Auth\AuthenticationRepositoryInterface::class,
            \Modules\Auth\Repositories\AuthenticationRepository::class
        );

        $this->app->singleton(
            \Modules\Core\Contracts\Auth\TokenManagerInterface::class,
            \Modules\Auth\Services\TokenManager::class
        );

        $this->app->bind(
            \Modules\Core\Contracts\Auth\AuthenticationRepositoryInterface::class,
            \Modules\Auth\Repositories\MockAuthenticationRepository::class
        );

        $this->app->bind(
            \Modules\Core\Contracts\Auth\TokenManagerInterface::class,
            \Modules\Auth\Services\DeterministicTokenManager::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Memuat konfigurasi rute khusus untuk modul Auth secara otomatis jika ada
        if (file_exists(__DIR__ . '/../Routes/api.php')) {
            $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');
        }

        // Daftarkan rute API modul secara otomatis ke dalam HTTP Kernel global
        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        $routeFilePath = base_path('Modules/Auth/Routes/api.php');

        // Defensive Guard: Pastikan berkas rute fisik benar-benar eksis sebelum di-load
        if (file_exists($routeFilePath)) {
            Route::prefix('api')
                ->middleware('api')
                ->group($routeFilePath);
        }
    }
}
