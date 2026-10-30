<?php

namespace Modules\Finance;

use Illuminate\Support\Facades\Event;
use Modules\Academic\Contracts\Events\EnrollmentClosed;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Application\Dashboard\DashboardCards;
use Modules\Core\Application\Settings\SettingRegistry;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\Core\Support\ModuleServiceProvider;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Application\Invoice\DueDaySetting;
use Modules\Finance\Application\Invoice\VoidInvoicesAfterExit;
use Modules\Finance\Application\Report\Arrears;

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

        // Kartu Beranda "Tunggakan siswa" (PRD-003 FN-4a §17): pemilik finance.billing.view, sesuai cakupannya.
        $this->app->make(DashboardCards::class)->register('finance.arrears', fn (): ?array => $this->arrearsCard());
    }

    /**
     * @return array{key: string, title: string, value: int, description: string, href: string, tone: 'default'|'warning'|'danger'}|null
     */
    private function arrearsCard(): ?array
    {
        $context = $this->app->make(TenantContext::class)->get();

        if ($context === null) {
            return null;
        }

        $visible = $this->app->make(AuthorizationService::class)->visibleNodeIds(FinanceAccess::BILLING_VIEW);

        if ($visible === []) {
            return null;
        }

        $organizationIds = [];

        foreach (Organization::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $context->tenantId)
            ->whereIn('id', $visible)
            ->where('type', OrganizationType::Lembaga->value)
            ->pluck('id') as $id) {
            $organizationIds[] = (string) $id;
        }

        $students = 0;
        $total = 0;
        $organizations = 0;

        foreach ($this->app->make(Arrears::class)->byOrganization($context->tenantId, $organizationIds, BusinessClock::todayString()) as $row) {
            $students += $row['students'];
            $total += $row['total'];
            $organizations++;
        }

        return [
            'key' => 'finance.arrears',
            'title' => 'Tunggakan siswa',
            'value' => $students,
            'description' => $students === 0
                ? 'Tidak ada tagihan siswa yang lewat jatuh tempo.'
                : Rupiah::format($total).' lewat jatuh tempo'.($organizations > 1 ? " di {$organizations} lembaga." : '.'),
            'href' => '/keuangan/tunggakan',
            'tone' => $students > 0 ? 'warning' : 'default',
        ];
    }
}
