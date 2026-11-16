<?php

namespace Modules\Dormitory\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Person\Gender;
use Modules\Core\Support\BusinessClock;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\Report\OccupancyReport;
use Modules\Dormitory\Application\Report\OccupancyReportExport;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Asrama → Laporan hunian (PRD-008 §12 DM-6, §21): hunian sekarang per asrama/gedung/kamar, pergerakan dalam rentang
 * tanggal, dan unduhan .xlsx. Butuh `dormitory.residents.view`; hanya asrama yang node pemiliknya terlihat. Saringan
 * dari alamat: asrama, jk (putra/putri), dari, sampai, tab, halaman.
 */
final class OccupancyReportController
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function index(Request $request, OccupancyReport $report): Response
    {
        Gate::authorize(DormitoryAccess::RESIDENTS_VIEW);

        $options = $this->dormitoryOptions();
        $scope = $this->scope($request, $options);
        $range = OccupancyReport::range($request->query('dari'), $request->query('sampai'), BusinessClock::today());
        $history = $request->query('tab') === 'riwayat';

        $events = [];
        $page = 1;
        $lastPage = 1;

        if ($history) {
            $all = $report->events($scope['ids'], $scope['gender'], $range['from'], $range['to']);
            $lastPage = max(1, (int) ceil(count($all) / OccupancyReport::EVENTS_PER_PAGE));
            $page = min(max(1, $request->integer('halaman', 1)), $lastPage);
            $events = ['rows' => array_slice($all, ($page - 1) * OccupancyReport::EVENTS_PER_PAGE, OccupancyReport::EVENTS_PER_PAGE), 'total' => count($all), 'page' => $page, 'last_page' => $lastPage];
        }

        return Inertia::render('dormitory/report', [
            'filters' => [
                'dormitory' => $scope['dormitory'],
                'gender' => $scope['gender'] === null ? null : ($scope['gender'] === Gender::Male->value ? 'putra' : 'putri'),
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
            ],
            'rangeAdjusted' => $range['adjusted'],
            'today' => BusinessClock::todayString(),
            'options' => ['dormitories' => $options],
            'current' => $report->current($scope['ids'], $scope['gender']),
            // Tab Riwayat dimuat saat dibuka.
            'movements' => $history ? $report->movements($scope['ids'], $scope['gender'], $range['from'], $range['to']) : null,
            'events' => $history ? $events : null,
        ]);
    }

    public function export(Request $request, OccupancyReport $report, OccupancyReportExport $export): BinaryFileResponse
    {
        Gate::authorize(DormitoryAccess::RESIDENTS_VIEW);

        $options = $this->dormitoryOptions();
        $scope = $this->scope($request, $options);
        $today = BusinessClock::today();
        $range = OccupancyReport::range($request->query('dari'), $request->query('sampai'), $today);
        $dormitory = $scope['dormitory'] === null ? null : Dormitory::query()->whereKey($scope['dormitory'])->first(['id', 'code', 'name']);
        $label = ($dormitory === null ? 'semua asrama' : $dormitory->name).match ($scope['gender']) {
            Gender::Male->value => ', gedung putra',
            Gender::Female->value => ', gedung putri',
            default => '',
        };

        $path = tempnam(sys_get_temp_dir(), 'educore-hunian-');

        if ($path === false) {
            abort(500, 'Laporan hunian tidak bisa dibuat.');
        }

        $export->write(
            $path,
            $report->current($scope['ids'], $scope['gender']),
            $report->movements($scope['ids'], $scope['gender'], $range['from'], $range['to']),
            $report->events($scope['ids'], $scope['gender'], $range['from'], $range['to']),
            $range['from'],
            $range['to'],
            $label,
        );

        return response()
            ->download($path, OccupancyReportExport::fileName($today, $dormitory?->code), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * Asrama yang terlihat (aktif & nonaktif).
     *
     * @return list<array{value: string, label: string}>
     */
    private function dormitoryOptions(): array
    {
        $visible = $this->authorization->visibleNodeIds(DormitoryAccess::RESIDENTS_VIEW);
        $options = [];

        foreach (Dormitory::query()->whereIn('organization_id', $visible)->orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'is_active']) as $dormitory) {
            $options[] = ['value' => $dormitory->id, 'label' => $dormitory->name.($dormitory->is_active ? '' : ' (nonaktif)')];
        }

        return $options;
    }

    /**
     * Saringan dari alamat; asrama yang tidak terlihat diabaikan (kembali ke semua).
     *
     * @param  list<array{value: string, label: string}>  $options
     * @return array{dormitory: string|null, ids: list<string>, gender: string|null}
     */
    private function scope(Request $request, array $options): array
    {
        $ids = array_column($options, 'value');
        $requested = $request->query('asrama');
        $dormitory = is_string($requested) && in_array($requested, $ids, true) ? $requested : null;

        return [
            'dormitory' => $dormitory,
            'ids' => $dormitory === null ? $ids : [$dormitory],
            'gender' => match ($request->query('jk')) {
                'putra' => Gender::Male->value,
                'putri' => Gender::Female->value,
                default => null,
            },
        ];
    }
}
