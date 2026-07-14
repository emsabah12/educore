<?php

declare(strict_types=1);

namespace Modules\PPDB\Providers;

use Illuminate\Support\ServiceProvider;

final class PPDBServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Tempat registrasi service internal modul PPDB nantinya
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Tempat bootstrap routing/views/migrations modul PPDB nantinya
    }
}
