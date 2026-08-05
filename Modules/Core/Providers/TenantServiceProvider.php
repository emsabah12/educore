<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Services\TenantContext;

final class TenantServiceProvider extends ServiceProvider
{
    /**
     * TenantContext menyimpan mutable runtime state.
     *
     * Binding scoped memastikan satu instance digunakan dalam
     * satu request/job scope dan dibuat ulang pada scope berikutnya.
     */
    public function register(): void
    {
        $this->app->scoped(
            TenantContextInterface::class,
            TenantContext::class,
        );
    }

    public function boot(): void
    {
        // Tidak ada bootstrapping tenancy tambahan.
    }
}
