<?php

namespace Modules\Core\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Kelas dasar untuk ServiceProvider setiap modul (ADR-001 §2.2).
 *
 * Konvensi folder modul:
 *   modules/<Modul>/Database/Migrations  → migrasi milik modul
 *   modules/<Modul>/Http/routes.php      → route web (Inertia) milik modul
 *
 * Provider modul didaftarkan MANUAL di bootstrap/providers.php.
 * Tidak ada manifest, auto-discovery, atau enable/disable saat runtime.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Path absolut ke root folder modul. Cukup kembalikan __DIR__ di subclass.
     */
    abstract protected function modulePath(): string;

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->modulePath().'/Database/Migrations');
        $this->loadModuleWebRoutes();
    }

    /**
     * Route modul selalu dibungkus middleware group "web" agar mendapat
     * session, CSRF, dan Inertia — sama seperti routes/web.php.
     */
    protected function loadModuleWebRoutes(): void
    {
        $routesFile = $this->modulePath().'/Http/routes.php';

        if ($this->app->routesAreCached() || ! is_file($routesFile)) {
            return;
        }

        Route::middleware('web')->group($routesFile);
    }
}
