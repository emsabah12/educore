<?php

namespace Modules\Finance;

use Illuminate\Support\Facades\Event;
use Modules\Academic\Contracts\Events\EnrollmentClosed;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Authorization\AccessCatalog;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Application\Dashboard\DashboardCards;
use Modules\Core\Application\Portal\GuardianPortalLinks;
use Modules\Core\Application\Settings\SettingRegistry;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\Core\Support\ModuleServiceProvider;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Cash\ExpenseApprovalLimitSetting;
use Modules\Finance\Application\Cash\ExpenseList;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Application\Invoice\DueDaySetting;
use Modules\Finance\Application\Invoice\VoidInvoicesAfterExit;
use Modules\Finance\Application\Report\Arrears;
use Modules\Finance\Domain\Cash\ExpenseStatus;

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
        $this->app->make(SettingRegistry::class)
            ->define(DueDaySetting::definition())
            // Batas persetujuan pengeluaran berjenjang per jenjang/lembaga (PRD-004 §16, KS-5).
            ->define(ExpenseApprovalLimitSetting::definition());

        // Siswa keluar → tagihan bulan sesudahnya yang belum dibayar dibatalkan (OD-FN-07, FN-2b).
        Event::listen(EnrollmentClosed::class, [VoidInvoicesAfterExit::class, 'handle']);

        // Kartu Beranda "Tunggakan siswa" (PRD-003 FN-4a §17): pemilik finance.billing.view, sesuai cakupannya.
        $this->app->make(DashboardCards::class)->register('finance.arrears', fn (): ?array => $this->arrearsCard());

        // Kartu Beranda pengeluaran (PRD-004 §6, KS-3): menunggu persetujuan (penyetuju) & siap direalisasi (bendahara).
        $this->app->make(DashboardCards::class)
            ->register('finance.expenses_pending', fn (): ?array => $this->expenseCard(FinanceAccess::EXPENSES_APPROVE))
            ->register('finance.expenses_approved', fn (): ?array => $this->expenseCard(FinanceAccess::CASH_MANAGE));

        // Tombol "Tagihan" di halaman anak pada portal orang tua/wali (FN-5).
        $this->app->make(GuardianPortalLinks::class)->register('finance.billing', 'Tagihan', 'tagihan', 'receipt');
    }

    /**
     * @return array{key: string, title: string, value: int, description: string, href: string, tone: 'default'|'warning'|'danger'}|null
     */
    private function expenseCard(string $permission): ?array
    {
        $context = $this->app->make(TenantContext::class)->get();

        if ($context === null) {
            return null;
        }

        $authorization = $this->app->make(AuthorizationService::class);
        $yayasan = $authorization->inspectTarget($permission, null, null)->allowed();
        $organizationIds = $authorization->visibleNodeIds($permission);

        if (! $yayasan && $organizationIds === []) {
            return null;
        }

        $approving = $permission === FinanceAccess::EXPENSES_APPROVE;
        $list = $this->app->make(ExpenseList::class);
        $count = $list->count(
            $context->tenantId,
            $organizationIds,
            $yayasan,
            $approving ? ExpenseStatus::Requested : ExpenseStatus::Approved,
            // Pengajuan sendiri tidak bisa disetujui sendiri, jadi tidak dihitung.
            $approving ? $context->membershipId : null,
        );

        // KS-5: penyetuju tingkat Yayasan juga melihat pengajuan lembaga yang menunggu persetujuan kedua.
        if ($approving && $yayasan) {
            $count += $list->countAwaitingFoundation($context->tenantId, $context->membershipId);
        }

        return [
            'key' => $approving ? 'finance.expenses_pending' : 'finance.expenses_approved',
            'title' => $approving ? 'Pengajuan menunggu persetujuan' : 'Pengeluaran siap direalisasi',
            'value' => $count,
            'description' => $count === 0
                ? ($approving ? 'Tidak ada pengajuan pengeluaran yang menunggu.' : 'Tidak ada pengeluaran disetujui yang belum direalisasi.')
                : ($approving ? "{$count} pengajuan pengeluaran perlu disetujui atau ditolak." : "{$count} pengeluaran disetujui menunggu dicatat keluar."),
            'href' => '/keuangan/pengeluaran?status='.($approving ? ExpenseStatus::Requested->value : ExpenseStatus::Approved->value),
            'tone' => $count > 0 ? 'warning' : 'default',
        ];
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
