<?php

namespace Modules\Dormitory;

use Inertia\Inertia;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\ModuleServiceProvider;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\Supervision\SupervisorDirectory;

/**
 * Modul Dormitory (Asrama), spesifikasi: PRD-008.
 *
 * Aturan dependency: Dormitory → Core, Academic & HR (hanya kontrak publik). Modul lain tidak bergantung pada
 * Dormitory. Provider ini di-boot SETELAH Finance (bootstrap/providers.php) karena role bawaan Core diperluas di sini.
 */
class DormitoryServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function boot(): void
    {
        parent::boot();

        DormitoryAccess::register($this->app->make(AccessCatalog::class));
        AuditAction::registerLabels(DormitoryAuditAction::labels());

        // Menu Untuk saya → Kamar saya (DM-3): hanya bagi pegawai aktif yang punya penugasan wali kamar aktif.
        Inertia::share('dormitory', fn (): array => ['myRooms' => $this->hasMyRooms()]);
    }

    private function hasMyRooms(): bool
    {
        $context = $this->app->make(TenantContext::class)->get();

        return $context !== null
            && $this->app->make(SupervisorDirectory::class)->roomIdsOf($context->tenantId, $context->membershipId) !== [];
    }
}
