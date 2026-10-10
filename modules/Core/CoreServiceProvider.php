<?php

namespace Modules\Core;

use Illuminate\Auth\Access\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Authorization\AccessScope;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Application\Settings\SettingRegistry;
use Modules\Core\Console\SyncAccessCommand;
use Modules\Core\Domain\Identity\User;
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
        $this->app->scoped(AccessScope::class);
        $this->app->scoped(AuthorizationService::class);

        // Katalog yang diisi modul saat boot; berlaku selama aplikasi hidup.
        $this->app->singleton(AccessCatalog::class);
        $this->app->singleton(SettingRegistry::class);
    }

    public function boot(): void
    {
        parent::boot();

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('work.context', EnsureWorkContext::class);

        CoreAccess::register($this->app->make(AccessCatalog::class));

        $this->registerGate();

        if ($this->app->runningInConsole()) {
            $this->commands([SyncAccessCommand::class]);
        }
    }

    /**
     * Semua ability yang terdaftar di AccessCatalog dijawab oleh AuthorizationService
     * (PRD-000 §7.1, ADR-001). Ability lain (bukan permission katalog) diteruskan ke
     * gate/policy Laravel biasa. Superadmin tidak mendapat jalan pintas (OD-12).
     */
    private function registerGate(): void
    {
        Gate::before(/** @param array<int, mixed> $arguments */ function (mixed $user, string $ability, array $arguments): ?Response {
            if (! $this->app->make(AccessCatalog::class)->hasPermission($ability)) {
                return null;
            }

            if (! $user instanceof User) {
                return Response::deny('Anda tidak memiliki izin untuk tindakan ini.');
            }

            return $this->app->make(AuthorizationService::class)->inspect($user, $ability, $arguments[0] ?? null);
        });
    }
}
