<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Authorization\FinanceAccess;
use Modules\Finance\Application\Report\ReceiptRecap;
use Modules\Finance\Application\Savings\SavingsExport;
use Modules\Finance\Application\Savings\SavingsReport;
use Modules\Finance\Http\Controllers\Concerns\WorksPerOrganization;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Halaman Tabungan (PRD-005 §6, TB-2): ringkasan titipan per lembaga, daftar saldo per siswa, rekap bulanan, dan
 * unduhan .xlsx. Butuh `finance.savings.view`; lembaga di luar cakupan → 404.
 *
 * Saringan (query): `lembaga`, `kelas`, `cari`, `semua=1` (termasuk saldo Rp 0), `halaman`, `bulan` (Y-m, rekap).
 */
final class SavingsReportController
{
    use WorksPerOrganization;

    public const SEARCH_MAX_LENGTH = 100;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, SavingsReport $report): Response
    {
        $organizations = $this->savingsOrganizations();

        if ($organizations === []) {
            abort(403);
        }

        $tenantId = $this->tenantId();
        $organizationIds = array_column($organizations, 'value');
        $organizationId = $this->pick($organizationIds, $request->query('lembaga')) ?? $organizationIds[0];
        $names = array_column($organizations, 'label', 'value');

        $summary = [];

        foreach ($report->byOrganization($tenantId, $organizationIds) as $row) {
            $summary[] = $row + ['name' => $names[$row['organization_id']] ?? ''];
        }

        [$group, $search, $positiveOnly] = $this->filters($request, $report, $organizationId);
        $data = $report->balances($tenantId, $organizationId, $group, $search, $positiveOnly);
        $students = $data['students'];
        $lastPage = max(1, (int) ceil(count($students) / SavingsReport::PER_PAGE));
        $page = min(max(1, $request->integer('halaman', 1)), $lastPage);

        $months = ReceiptRecap::monthOptions(BusinessClock::today());
        $month = $this->pick(array_column($months, 'value'), $request->query('bulan')) ?? $months[0]['value'];

        return Inertia::render('finance/savings', [
            'organizations' => $organizations,
            'organizationId' => $organizationId,
            'today' => BusinessClock::todayString(),
            'summary' => $summary,
            'classOptions' => SavingsReport::classOptions($data['classes']),
            'filters' => ['kelas' => $group, 'cari' => $search ?? '', 'semua' => ! $positiveOnly],
            'balances' => [
                'total' => $data['total'],
                'student_count' => count($students),
                'classes' => $data['classes'],
                'students' => array_slice($students, ($page - 1) * SavingsReport::PER_PAGE, SavingsReport::PER_PAGE),
                'page' => $page,
                'last_page' => $lastPage,
            ],
            'months' => $months,
            'month' => $month,
            'monthly' => $report->monthly($tenantId, $organizationId, $month),
        ]);
    }

    public function exportBalances(Request $request, SavingsReport $report, SavingsExport $export): BinaryFileResponse
    {
        $organization = $this->requestedOrganization($request);
        [$group, $search, $positiveOnly] = $this->filters($request, $report, $organization->id);
        $today = BusinessClock::todayString();
        $path = $this->tempFile();

        $export->writeBalances($this->tenantId(), $organization->id, $organization->name, $today, $group, $search, $positiveOnly, $path);

        return $this->download($path, SavingsExport::balancesFileName($organization->code, $today));
    }

    public function exportMonthly(Request $request, SavingsExport $export): BinaryFileResponse
    {
        $organization = $this->requestedOrganization($request);
        $months = ReceiptRecap::monthOptions(BusinessClock::today());
        $month = $request->query('bulan');

        // Bulan wajib salah satu pilihan (pick() jatuh ke pilihan pertama, jadi dicek sendiri).
        if (! is_string($month) || ! in_array($month, array_column($months, 'value'), true)) {
            abort(404);
        }

        $label = array_column($months, 'label', 'value')[$month];
        $path = $this->tempFile();

        $export->writeMonthly($this->tenantId(), $organization->id, $organization->name, $month, $label, $path);

        return $this->download($path, SavingsExport::monthlyFileName($organization->code, $month));
    }

    /**
     * Lembaga aktif (node LEMBAGA) yang terlihat dengan izin lihat tabungan, urut nama.
     *
     * @return list<array{value: string, label: string}>
     */
    private function savingsOrganizations(): array
    {
        $options = [];

        foreach (Organization::query()
            ->whereIn('id', $this->authorization->visibleNodeIds(FinanceAccess::SAVINGS_VIEW))
            ->where('type', OrganizationType::Lembaga->value)
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get() as $organization) {
            $options[] = ['value' => $organization->id, 'label' => $organization->name];
        }

        return $options;
    }

    /**
     * Lembaga dari `?lembaga=` untuk unduhan; di luar cakupan lihat tabungan → 404.
     */
    private function requestedOrganization(Request $request): Organization
    {
        $organizationId = $request->query('lembaga');

        if (! is_string($organizationId) || ! in_array($organizationId, array_column($this->savingsOrganizations(), 'value'), true)) {
            abort(404);
        }

        return Organization::query()->whereKey($organizationId)->firstOrFail();
    }

    /**
     * Saringan dari query: kelas (hanya pilihan yang ada), cari (≤ 100 karakter), saldo > 0 (bawaan) atau semua.
     *
     * @return array{0: string|null, 1: string|null, 2: bool}
     */
    private function filters(Request $request, SavingsReport $report, string $organizationId): array
    {
        $group = null;
        $requested = $request->query('kelas');

        if (is_string($requested) && $requested !== '') {
            $options = SavingsReport::classOptions($report->balances($this->tenantId(), $organizationId, positiveOnly: false)['classes']);
            $group = in_array($requested, array_column($options, 'value'), true) ? $requested : null;
        }

        $search = trim($request->string('cari')->value());
        $search = $search === '' ? null : mb_substr($search, 0, self::SEARCH_MAX_LENGTH);

        return [$group, $search, ! $request->boolean('semua')];
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'educore-tabungan-');

        if ($path === false) {
            abort(500, 'File tabungan tidak bisa dibuat.');
        }

        return $path;
    }

    private function download(string $path, string $name): BinaryFileResponse
    {
        return response()
            ->download($path, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }
}
