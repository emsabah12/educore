<?php

declare(strict_types=1);

namespace Modules\Auth\Providers;

use Illuminate\Support\ServiceProvider;
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
    }
}
