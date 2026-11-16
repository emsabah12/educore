<?php

namespace Modules\Dormitory\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Support\BusinessClock;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\Placement\PlacementDirectory;
use Modules\Dormitory\Application\Placement\ResidentExport;
use Modules\Dormitory\Domain\Facility\Building;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Placement\PlacementCategory;
use Modules\Dormitory\Http\Controllers\Concerns\HandlesFacilityActions;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Menu Asrama → Penghuni (PRD-008 §16.6): penghuni Aktif/Direncanakan di semua asrama dalam cakupan
 * `dormitory.residents.view`, unduhan .xlsx sesuai saringan (DM-6), dan riwayat hunian per orang (hanya di asrama yang
 * terlihat).
 */
final class ResidentController
{
    use HandlesFacilityActions;

    public function __construct(private readonly AuthorizationService $authorization) {}

    public function index(Request $request, PlacementDirectory $directory): Response
    {
        Gate::authorize(DormitoryAccess::RESIDENTS_VIEW);

        $visible = $this->authorization->visibleNodeIds(DormitoryAccess::RESIDENTS_VIEW);
        $dormitories = [];

        foreach (Dormitory::query()->whereIn('organization_id', $visible)->orderBy('name')->get(['id', 'name']) as $dormitory) {
            $dormitories[] = ['value' => $dormitory->id, 'label' => $dormitory->name];
        }

        $filters = self::filters($request, array_column($dormitories, 'value'));

        return Inertia::render('dormitory/residents', [
            'filters' => $filters,
            'options' => [
                'dormitories' => $dormitories,
                'buildings' => self::buildingOptions($filters['dormitory']),
                'categories' => PlacementCategory::options(),
            ],
            'residents' => $directory->search($this->tenantId(), $visible, $filters),
        ]);
    }

    /**
     * Unduh daftar penghuni sesuai saringan halaman (.xlsx, PRD-008 §10, DM-6).
     */
    public function export(Request $request, PlacementDirectory $directory, ResidentExport $export): BinaryFileResponse
    {
        Gate::authorize(DormitoryAccess::RESIDENTS_VIEW);

        $visible = $this->authorization->visibleNodeIds(DormitoryAccess::RESIDENTS_VIEW);
        $dormitoryIds = Dormitory::query()->whereIn('organization_id', $visible)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
        $dormitoryIds = array_values($dormitoryIds);
        $rows = $directory->searchAll($this->tenantId(), $visible, self::filters($request, $dormitoryIds));
        $path = tempnam(sys_get_temp_dir(), 'educore-penghuni-');

        if ($path === false) {
            abort(500, 'File daftar penghuni tidak bisa dibuat.');
        }

        $export->write($path, $rows);

        return response()
            ->download($path, ResidentExport::fileName(BusinessClock::today()), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    public function person(string $membership, PlacementDirectory $directory): Response
    {
        Gate::authorize(DormitoryAccess::RESIDENTS_VIEW);

        $history = Str::isUuid($membership)
            ? $directory->person($this->tenantId(), $membership, $this->authorization->visibleNodeIds(DormitoryAccess::RESIDENTS_VIEW))
            : null;

        if ($history === null) {
            abort(404);
        }

        return Inertia::render('dormitory/resident-history', $history);
    }

    /**
     * Saringan dari alamat; asrama/gedung yang tidak terlihat diabaikan.
     *
     * @param  list<string>  $dormitoryIds
     * @return array{dormitory: string|null, building: string|null, status: string, category: string|null, q: string, review: bool}
     */
    public static function filters(Request $request, array $dormitoryIds, ?string $fixedDormitory = null): array
    {
        $dormitory = $fixedDormitory ?? $request->query('asrama');
        $dormitory = is_string($dormitory) && in_array($dormitory, $dormitoryIds, true) ? $dormitory : null;
        $building = $request->query('gedung');
        $building = $dormitory !== null && is_string($building) && in_array($building, array_column(self::buildingOptions($dormitory), 'value'), true) ? $building : null;
        $status = $request->query('status');
        $category = PlacementCategory::tryFrom((string) $request->query('kategori'));

        return [
            'dormitory' => $dormitory,
            'building' => $building,
            'status' => in_array($status, ['aktif', 'rencana'], true) ? $status : 'semua',
            'category' => $category?->value,
            'q' => mb_substr(trim((string) $request->query('q')), 0, 100),
            // Hanya yang Perlu ditinjau (DM-5a, §19.1).
            'review' => $request->query('tinjau') === '1',
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function buildingOptions(?string $dormitoryId): array
    {
        if ($dormitoryId === null) {
            return [];
        }

        $options = [];

        foreach (Building::query()->where('dormitory_id', $dormitoryId)->orderBy('code')->get(['id', 'code', 'name']) as $building) {
            $options[] = ['value' => $building->id, 'label' => "{$building->name} ({$building->code})"];
        }

        return $options;
    }
}
