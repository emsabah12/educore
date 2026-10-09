<?php

namespace Modules\Core;

use Illuminate\Routing\Router;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Http\Middleware\EnsureWorkContext;
use Modules\Core\Support\ModuleServiceProvider;

/**
 * Modul Core: Tenancy, pohon lembaga, Person, User, Membership,
 * Authorization, scoped settings, dan Audit (PRD-000).
 *
 * Aturan dependency: Core tidak boleh bergantung pada modul bisnis apa pun.
 */
class CoreServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        // `scoped`: satu instance per request, otomatis direset di antara request.
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        parent::boot();

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('work.context', EnsureWorkContext::class);
    }
}
