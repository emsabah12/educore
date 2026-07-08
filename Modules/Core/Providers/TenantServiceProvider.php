<?php

namespace Modules\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Entities\Tenant;
use Modules\Core\Contracts\TenantContextInterface;
use Modules\Core\Services\TenantContext;

class TenantServiceProvider extends ServiceProvider
{
    /**
     * Daftarkan service apa pun ke dalam container.
     */
    public function register(): void
    {
        // Tempat registrasi komponen singleton context kedepannya
        // Memastikan TenantContext diikat sebagai Singleton agar nilainya konsisten di sepanjang request lifecycle
        $this->app->singleton(TenantContextInterface::class, function ($app) {
            return new TenantContext();
        });
    }

    /**
     * Bootstrapping untuk service aplikasi.
     */
    public function boot(): void
    {
        // Memastikan konfigurasi boot database atau event listener tenant berjalan di sini
    }
}