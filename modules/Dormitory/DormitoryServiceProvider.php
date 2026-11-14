<?php

namespace Modules\Dormitory;

use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Support\ModuleServiceProvider;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;

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
    }
}
