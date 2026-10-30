<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;
use Modules\Finance\Application\Report\CashFlowExport;
use Modules\Finance\Application\Report\CashFlowReport;
use Modules\Finance\Http\Controllers\Concerns\WorksPerCashBook;
use Modules\Finance\Http\Controllers\Concerns\WorksPerOrganization;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Laporan arus kas (PRD-004 §6, KS-4): satu kas atau semua kas dalam cakupan `finance.cash.view`, per bulan atau
 * setahun; unduh .xlsx. Kas di luar cakupan diabaikan di halaman; unduhan untuk kas/periode tidak sah → 404.
 */
final class CashReportController
{
    use WorksPerCashBook;
    use WorksPerOrganization;

    private const ALL = 'semua';

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request, CashFlowReport $report): Response
    {
        $books = $this->books();

        if ($books === []) {
            abort(403);
        }

        $scope = $this->scopeOf($books, $request->query('kas'));
        $periods = CashFlowReport::periodOptions(BusinessClock::today());
        $period = $this->pick(array_column($periods, 'value'), $request->query('periode'), BusinessClock::today()->format('Y-m')) ?? BusinessClock::today()->format('Y-m');
        $result = $report->build($this->tenantId(), $scope['organizations'], $scope['yayasan'], $period);
        $names = $this->bookNames($books);

        // Urutan sama dengan pilihan kas: Kas Yayasan dulu, lalu lembaga urut nama.
        $position = array_flip(array_keys($names));
        $bookRows = [];

        foreach ($result['books'] as $book) {
            $bookRows[] = $book + ['name' => $names[$book['organization_id'] ?? ''] ?? ''];
        }

        usort($bookRows, fn (array $a, array $b): int => ($position[$a['organization_id'] ?? ''] ?? PHP_INT_MAX) <=> ($position[$b['organization_id'] ?? ''] ?? PHP_INT_MAX));

        $result['books'] = count($bookRows) > 1 ? $bookRows : [];

        return Inertia::render('finance/cash-report', [
            'books' => count($books) > 1 ? [['value' => self::ALL, 'label' => 'Semua kas'], ...$books] : $books,
            'bookKey' => $scope['key'],
            'periods' => $periods,
            'period' => $period,
            'report' => $result,
        ]);
    }

    public function export(Request $request, CashFlowExport $export): BinaryFileResponse
    {
        $books = $this->books();
        $requested = $request->query('kas');
        $period = $request->query('periode');

        if ($books === [] || ! is_string($requested) || ($requested !== self::ALL && ! in_array($requested, array_column($books, 'value'), true))
            || ! is_string($period) || ! in_array($period, array_column(CashFlowReport::periodOptions(BusinessClock::today()), 'value'), true)) {
            abort(404);
        }

        $scope = $this->scopeOf($books, $requested);
        $names = $this->bookNames($books);

        if ($scope['key'] === self::ALL) {
            $label = 'semua kas';
            $file = 'semua-kas';
        } elseif ($scope['key'] === self::YAYASAN_KEY) {
            $label = 'Kas Yayasan';
            $file = 'yayasan';
        } else {
            $organization = Organization::query()->whereKey($scope['key'])->firstOrFail();
            $label = 'kas '.$organization->name;
            $file = $organization->code;
        }

        $path = tempnam(sys_get_temp_dir(), 'educore-laporan-kas-');

        if ($path === false) {
            abort(500, 'Laporan tidak bisa dibuat.');
        }

        $export->write($this->tenantId(), $scope['organizations'], $scope['yayasan'], $period, "Laporan arus kas {$label} — {$this->periodLabel($period)}", $names, $path);

        return response()
            ->download($path, CashFlowExport::fileName($file, $period), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * Kas yang dilaporkan. Lebih dari satu kas → bawaan "Semua kas".
     *
     * @param  non-empty-list<array{value: string, label: string}>  $books
     * @return array{key: string, organizations: list<string>, yayasan: bool}
     */
    private function scopeOf(array $books, mixed $requested): array
    {
        $keys = array_column($books, 'value');
        $key = is_string($requested) && in_array($requested, $keys, true)
            ? $requested
            : (count($books) > 1 ? self::ALL : $books[0]['value']);
        $selected = $key === self::ALL ? $keys : [$key];
        $organizations = [];

        foreach ($selected as $value) {
            if ($value !== self::YAYASAN_KEY) {
                $organizations[] = $value;
            }
        }

        return ['key' => $key, 'organizations' => $organizations, 'yayasan' => in_array(self::YAYASAN_KEY, $selected, true)];
    }

    /**
     * @param  list<array{value: string, label: string}>  $books
     * @return array<string, string> organization_id ('' untuk Yayasan) → nama kas
     */
    private function bookNames(array $books): array
    {
        $names = [];

        foreach ($books as $book) {
            $names[$book['value'] === self::YAYASAN_KEY ? '' : $book['value']] = $book['label'];
        }

        return $names;
    }

    private function periodLabel(string $period): string
    {
        foreach (CashFlowReport::periodOptions(BusinessClock::today()) as $option) {
            if ($option['value'] === $period) {
                return $option['label'];
            }
        }

        return $period;
    }
}
