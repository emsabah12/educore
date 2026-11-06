<?php

namespace Modules\Finance;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
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
use Modules\Finance\Application\Ledger\LedgerReports;
use Modules\Finance\Application\Ledger\LedgerSetup;
use Modules\Finance\Application\Online\HttpMidtransGateway;
use Modules\Finance\Application\Online\MidtransGateway;
use Modules\Finance\Application\Online\OnlinePaymentList;
use Modules\Finance\Application\Report\Arrears;
use Modules\Finance\Application\Savings\SavingsReport;
use Modules\Finance\Console\ProcessLedgerCommand;
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

    public function register(): void
    {
        // Klien Midtrans (PRD-006 MG-1): HTTP client Laravel; test bisa menggantinya dengan tiruan.
        $this->app->singleton(MidtransGateway::class, HttpMidtransGateway::class);
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

        // Jurnal otomatis pembukuan dari antrean (PRD-007 §4, PB-1).
        if ($this->app->runningInConsole()) {
            $this->commands([ProcessLedgerCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('finance:ledger-process')->everyMinute()->withoutOverlapping();
        });

        // Siswa keluar → tagihan bulan sesudahnya yang belum dibayar dibatalkan (OD-FN-07, FN-2b).
        Event::listen(EnrollmentClosed::class, [VoidInvoicesAfterExit::class, 'handle']);

        // Kartu Beranda "Tunggakan siswa" (PRD-003 FN-4a §17): pemilik finance.billing.view, sesuai cakupannya.
        $this->app->make(DashboardCards::class)->register('finance.arrears', fn (): ?array => $this->arrearsCard());

        // Kartu Beranda pengeluaran (PRD-004 §6, KS-3): menunggu persetujuan (penyetuju) & siap direalisasi (bendahara).
        $this->app->make(DashboardCards::class)
            ->register('finance.expenses_pending', fn (): ?array => $this->expenseCard(FinanceAccess::EXPENSES_APPROVE))
            ->register('finance.expenses_approved', fn (): ?array => $this->expenseCard(FinanceAccess::CASH_MANAGE));

        // Kartu Beranda "Kelebihan bayar online" (PRD-006 §9, MG-3): hanya bila ada yang belum dikembalikan.
        $this->app->make(DashboardCards::class)->register('finance.online_overpaid', fn (): ?array => $this->overpaidCard());

        // Kartu Beranda "Pembukuan tidak cocok" (PRD-007 §9, PB-2b): hanya bila ada selisih.
        $this->app->make(DashboardCards::class)->register('finance.ledger_mismatch', fn (): ?array => $this->ledgerMismatchCard());

        // Kartu Beranda "Titipan tabungan siswa" (PRD-005 §7, TB-2): pemilik finance.savings.view, sesuai cakupannya.
        $this->app->make(DashboardCards::class)->register('finance.savings', fn (): ?array => $this->savingsCard());

        // Tombol "Tagihan" di halaman anak pada portal orang tua/wali (FN-5).
        $this->app->make(GuardianPortalLinks::class)
            ->register('finance.billing', 'Tagihan', 'tagihan', 'receipt')
            // Tombol "Tabungan" (PRD-005 §6, TB-4).
            ->register('finance.savings', 'Tabungan', 'tabungan', 'piggy-bank');
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
    private function savingsCard(): ?array
    {
        $context = $this->app->make(TenantContext::class)->get();
        $organizationIds = $context === null ? [] : $this->visibleLembaga($context->tenantId, FinanceAccess::SAVINGS_VIEW);

        if ($context === null || $organizationIds === []) {
            return null;
        }

        $students = 0;
        $total = 0;
        $organizations = 0;

        foreach ($this->app->make(SavingsReport::class)->byOrganization($context->tenantId, $organizationIds) as $row) {
            $students += $row['students'];
            $total += $row['total'];
            $organizations++;
        }

        return [
            'key' => 'finance.savings',
            'title' => 'Titipan tabungan siswa',
            'value' => $students,
            'description' => $students === 0
                ? 'Belum ada saldo tabungan siswa.'
                : Rupiah::format($total).' milik '.$students.' siswa'.($organizations > 1 ? " di {$organizations} lembaga." : '.'),
            'href' => '/keuangan/tabungan',
            'tone' => 'default',
        ];
    }

    /**
     * @return array{key: string, title: string, value: int, description: string, href: string, tone: 'default'|'warning'|'danger'}|null
     */
    private function overpaidCard(): ?array
    {
        $context = $this->app->make(TenantContext::class)->get();
        $organizationIds = $context === null ? [] : $this->visibleLembaga($context->tenantId, FinanceAccess::BILLING_VIEW);

        if ($context === null || $organizationIds === []) {
            return null;
        }

        $overpaid = $this->app->make(OnlinePaymentList::class)->overpaid($context->tenantId, $organizationIds);

        if ($overpaid['count'] === 0) {
            return null;
        }

        return [
            'key' => 'finance.online_overpaid',
            'title' => 'Kelebihan bayar online',
            'value' => $overpaid['count'],
            'description' => Rupiah::format($overpaid['amount']).' perlu dikembalikan ke orang tua.',
            'href' => '/keuangan/pembayaran-online?status='.OnlinePaymentList::FILTER_OVERPAID,
            'tone' => 'warning',
        ];
    }

    /**
     * Selisih pembukuan vs buku pembantu (cek kecocokan), untuk pengelola pembukuan tingkat Yayasan. Tidak tampil selama
     * antrean/susun ulang masih berjalan (angka belum final).
     *
     * @return array{key: string, title: string, value: int, description: string, href: string, tone: 'default'|'warning'|'danger'}|null
     */
    private function ledgerMismatchCard(): ?array
    {
        $context = $this->app->make(TenantContext::class)->get();

        if ($context === null || ! $this->app->make(AuthorizationService::class)->inspectTarget(FinanceAccess::LEDGER_MANAGE, null, null)->allowed()) {
            return null;
        }

        $settings = $this->app->make(LedgerSetup::class)->settings($context->tenantId);

        if (! $settings['active'] || $settings['needs_rebuild'] || $settings['rebuilding'] || DB::table('fn_ledger_queue')->where('tenant_id', $context->tenantId)->exists()) {
            return null;
        }

        $count = 0;

        foreach ($this->app->make(LedgerReports::class)->reconciliation($context->tenantId, null) as $row) {
            if ($row['difference'] !== 0) {
                $count++;
            }
        }

        if ($count === 0) {
            return null;
        }

        return [
            'key' => 'finance.ledger_mismatch',
            'title' => 'Pembukuan tidak cocok',
            'value' => $count,
            'description' => "{$count} saldo pembukuan berbeda dengan buku kas/tagihan/tabungan. Buka Cek kecocokan.",
            'href' => '/keuangan/pembukuan/cek-kecocokan',
            'tone' => 'warning',
        ];
    }

    /**
     * Id node LEMBAGA yang terlihat dengan $permission di yayasan ini.
     *
     * @return list<string>
     */
    private function visibleLembaga(string $tenantId, string $permission): array
    {
        $visible = $this->app->make(AuthorizationService::class)->visibleNodeIds($permission);

        if ($visible === []) {
            return [];
        }

        $organizationIds = [];

        foreach (Organization::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $visible)
            ->where('type', OrganizationType::Lembaga->value)
            ->pluck('id') as $id) {
            $organizationIds[] = (string) $id;
        }

        return $organizationIds;
    }

    /**
     * @return array{key: string, title: string, value: int, description: string, href: string, tone: 'default'|'warning'|'danger'}|null
     */
    private function arrearsCard(): ?array
    {
        $context = $this->app->make(TenantContext::class)->get();
        $organizationIds = $context === null ? [] : $this->visibleLembaga($context->tenantId, FinanceAccess::BILLING_VIEW);

        if ($context === null || $organizationIds === []) {
            return null;
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
