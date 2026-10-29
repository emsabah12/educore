<?php

namespace Modules\Finance;

use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Support\ModuleServiceProvider;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Authorization\FinanceAccess;

/**
 * Modul Finance (Keuangan: tagihan & pembayaran siswa), spesifikasi: PRD-003.
 *
 * Aturan dependency: Finance → Core, Academic (hanya kontrak publik). Provider ini wajib di-boot
 * SETELAH AcademicServiceProvider (bootstrap/providers.php), karena kontrak Academic diisi di sana dan
 * role bawaan Core diperluas di sini.
 */
class FinanceServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function boot(): void
    {
        parent::boot();

        FinanceAccess::register($this->app->make(AccessCatalog::class));
        AuditAction::registerLabels(FinanceAuditAction::labels());
    }
}
