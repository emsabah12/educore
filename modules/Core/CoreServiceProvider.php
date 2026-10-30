<?php

namespace Modules\Core;

use Illuminate\Auth\Access\Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Application\Audit\AuthAuditSubscriber;
use Modules\Core\Application\Audit\UserAuditObserver;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Authorization\AccessScope;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Application\Dashboard\DashboardCards;
use Modules\Core\Application\Portal\GuardianPortalLinks;
use Modules\Core\Application\Settings\SettingRegistry;
use Modules\Core\Console\CreateAdminCommand;
use Modules\Core\Console\CreateSuperadminCommand;
use Modules\Core\Console\PruneAuditCommand;
use Modules\Core\Console\SyncAccessCommand;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Http\Middleware\EnsureWorkContext;
use Modules\Core\Support\ModuleServiceProvider;
use RuntimeException;
use Throwable;

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
        $this->app->singleton(DashboardCards::class);
        $this->app->singleton(GuardianPortalLinks::class);
    }

    public function boot(): void
    {
        parent::boot();

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('work.context', EnsureWorkContext::class);

        CoreAccess::register($this->app->make(AccessCatalog::class));

        $this->registerGate();
        $this->registerAudit();
        $this->registerHealthCheck();

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncAccessCommand::class,
                PruneAuditCommand::class,
                CreateSuperadminCommand::class,
                CreateAdminCommand::class,
            ]);
        }
    }

    /**
     * Jejak audit (OD-15): login/keluar lewat event auth, keamanan akun lewat observer User,
     * aksi lain langsung dari service. Log lama dihapus harian sesuai masa simpan (OD-16).
     */
    private function registerAudit(): void
    {
        Event::subscribe(AuthAuditSubscriber::class);
        User::observe(UserAuditObserver::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('educore:prune-audit')->dailyAt('02:00')->withoutOverlapping();
        });
    }

    /**
     * `/up` ikut memeriksa koneksi database. Bila gagal, pesan yang tampil sengaja umum,
     * tanpa nama host, database, atau pesan SQL (PRD-000 §9, [LAMA] HR-016 §3).
     */
    private function registerHealthCheck(): void
    {
        Event::listen(DiagnosingHealth::class, function (): void {
            try {
                DB::select('select 1');
            } catch (Throwable) {
                throw new RuntimeException('Layanan sedang bermasalah.');
            }
        });
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
