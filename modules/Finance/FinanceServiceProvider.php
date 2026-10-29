<?php

namespace Modules\Finance;

use Illuminate\Support\Facades\Event;
use Modules\Academic\Contracts\Events\EnrollmentClosed;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Settings\SettingRegistry;
use Modules\Core\Support\ModuleServiceProvider;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Invoice\DueDaySetting;
use Modules\Finance\Application\Invoice\VoidInvoicesAfterExit;

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

        // Jatuh tempo tagihan bulanan per jenjang/lembaga (PRD-003 §4, FN-2a).
        $this->app->make(SettingRegistry::class)->define(DueDaySetting::definition());

        // Siswa keluar → tagihan bulan sesudahnya yang belum dibayar dibatalkan (OD-FN-07, FN-2b).
        Event::listen(EnrollmentClosed::class, [VoidInvoicesAfterExit::class, 'handle']);
    }
}
