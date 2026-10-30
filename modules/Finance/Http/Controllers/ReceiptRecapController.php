<?php

namespace Modules\Finance\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Report\ReceiptRecap;
use Modules\Finance\Application\Report\ReceiptRecapExport;
use Modules\Finance\Http\Controllers\Concerns\WorksPerOrganization;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Rekap penerimaan (PRD-003 §6, FN-4b §18): satu bulan per lembaga (atau semua lembaga dalam cakupan),
 * rincian per hari, per jenis biaya, per lembaga, daftar transaksi; unduh .xlsx.
 * Butuh `finance.billing.view`; lembaga di luar cakupan diabaikan (unduhan → 404).
 */
final class ReceiptRecapController
{
    use WorksPerOrganization;

    /** Nilai saringan lembaga untuk seluruh lembaga dalam cakupan. */
    private const ALL = 'semua';

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, ReceiptRecap $recap): Response
    {
        $organizations = $this->organizations();

        if ($organizations === []) {
            abort(403);
        }

        $scope = $this->scope($organizations, $request->query('lembaga'));
        $months = ReceiptRecap::monthOptions(BusinessClock::today());
        $month = $this->pick(array_column($months, 'value'), $request->query('bulan')) ?? BusinessClock::today()->format('Y-m');
        $day = $this->day($month, $request->query('tanggal'));
        $ids = array_keys($scope['organizations']);

        $transactions = $recap->transactions($this->tenantId(), $ids, $month, $day);
        $lastPage = max(1, (int) ceil(count($transactions) / ReceiptRecap::PER_PAGE));
        $page = min(max(1, $request->integer('halaman', 1)), $lastPage);
        $pageRows = [];

        foreach (array_slice($transactions, ($page - 1) * ReceiptRecap::PER_PAGE, ReceiptRecap::PER_PAGE) as $row) {
            $pageRows[] = $row + ['organization' => $scope['organizations'][$row['organization_id']] ?? ''];
        }

        $summary = $recap->summary($this->tenantId(), $ids, $month);
        $organizationRows = [];

        foreach ($summary['organizations'] as $row) {
            $organizationRows[] = $row + ['name' => $scope['organizations'][$row['organization_id']] ?? ''];
        }

        return Inertia::render('finance/receipt-recap', [
            'organizations' => count($organizations) > 1
                ? [['value' => self::ALL, 'label' => 'Semua lembaga'], ...$organizations]
                : $organizations,
            'organizationId' => $scope['value'],
            'months' => $months,
            'month' => $month,
            'day' => $day,
            'summary' => [
                'totals' => $summary['totals'],
                'days' => $summary['days'],
                'fee_types' => $summary['fee_types'],
                'organizations' => count($scope['organizations']) > 1 ? $organizationRows : [],
            ],
            'transactions' => [
                'rows' => $pageRows,
                'total' => count($transactions),
                'page' => $page,
                'last_page' => $lastPage,
            ],
        ]);
    }

    public function export(Request $request, ReceiptRecapExport $export): BinaryFileResponse
    {
        $organizations = $this->organizations();
        $requested = $request->query('lembaga');

        if ($organizations === [] || ! is_string($requested)
            || ($requested !== self::ALL && ! in_array($requested, array_column($organizations, 'value'), true))) {
            abort(404);
        }

        $scope = $this->scope($organizations, $requested);
        $month = $request->query('bulan');

        if (! is_string($month) || ! in_array($month, array_column(ReceiptRecap::monthOptions(BusinessClock::today()), 'value'), true)) {
            abort(404);
        }

        if ($scope['value'] === self::ALL) {
            $scopeLabel = 'semua lembaga';
            $fileScope = 'semua-lembaga';
        } else {
            $organization = Organization::query()->whereKey($scope['value'])->firstOrFail();
            $scopeLabel = $organization->name;
            $fileScope = $organization->code;
        }

        $path = tempnam(sys_get_temp_dir(), 'educore-rekap-penerimaan-');

        if ($path === false) {
            abort(500, 'Rekap tidak bisa dibuat.');
        }

        $monthLabel = ReceiptRecap::monthLabel(CarbonImmutable::parse($month.'-01'));
        $export->write($this->tenantId(), $scope['organizations'], $scopeLabel, $month, $monthLabel, $path);

        return response()
            ->download($path, ReceiptRecapExport::fileName($fileScope, $month), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * Lembaga yang direkap. Lebih dari satu lembaga dalam cakupan → bawaan "Semua lembaga".
     *
     * @param  non-empty-list<array{value: string, label: string}>  $organizations
     * @return array{value: string, organizations: array<string, string>}
     */
    private function scope(array $organizations, mixed $requested): array
    {
        $names = array_column($organizations, 'label', 'value');

        if (count($organizations) > 1 && ($requested === self::ALL || ! is_string($requested) || ! isset($names[$requested]))) {
            return ['value' => self::ALL, 'organizations' => $names];
        }

        $id = is_string($requested) && isset($names[$requested]) ? $requested : $organizations[0]['value'];

        return ['value' => $id, 'organizations' => [$id => $names[$id]]];
    }

    /**
     * Tanggal di dalam bulan terpilih (Y-m-d), atau null.
     */
    private function day(string $month, mixed $requested): ?string
    {
        if (! is_string($requested) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) !== 1 || ! str_starts_with($requested, $month.'-')) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $requested);

        return $date !== null && $date->format('Y-m-d') === $requested ? $requested : null;
    }
}
