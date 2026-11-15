<?php

namespace Modules\Dormitory\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\Facility\FacilityCode;
use Modules\Dormitory\Application\Facility\FacilityDirectory;
use Modules\Dormitory\Application\Facility\ManageDormitories;
use Modules\Dormitory\Application\Facility\ManageRooms;
use Modules\Dormitory\Application\Placement\PlacementDirectory;
use Modules\Dormitory\Application\Supervision\SupervisorDirectory;
use Modules\Dormitory\Domain\Facility\CapacityBasis;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Facility\RoomComposition;
use Modules\Dormitory\Domain\Facility\YearEndPolicy;
use Modules\Dormitory\Domain\Placement\PlacementCategory;
use Modules\Dormitory\Http\Controllers\Concerns\HandlesFacilityActions;

/**
 * Halaman Asrama (PRD-008 §10, §15 DM-1): daftar asrama, detail asrama (gedung & kamar), tambah/ubah/nonaktifkan.
 *
 * Melihat: `dormitory.facilities.view` di node pemilik. Mengelola: `dormitory.facilities.manage` di node pemilik
 * (Gate dengan datanya: di luar cakupan 404, tanpa izin 403).
 */
final class DormitoryController
{
    use HandlesFacilityActions;

    public function __construct(private readonly AuthorizationService $authorization) {}

    public function index(Request $request, FacilityDirectory $directory): Response
    {
        Gate::authorize(DormitoryAccess::FACILITIES_VIEW);

        $visible = $this->authorization->visibleNodeIds(DormitoryAccess::FACILITIES_VIEW);
        $manageable = $this->authorization->visibleNodeIds(DormitoryAccess::FACILITIES_MANAGE);
        $owners = $directory->ownerFilterOptions($this->tenantId(), $visible);
        $requested = $request->query('lembaga');
        $organization = is_string($requested) && in_array($requested, array_column($owners, 'value'), true) ? $requested : null;
        $includeInactive = $request->query('status') === 'semua';

        $dormitories = [];

        foreach ($directory->dormitories($this->tenantId(), $visible, $includeInactive, $organization) as $row) {
            $dormitories[] = $row + ['can_manage' => in_array($row['organization_id'], $manageable, true)];
        }

        return Inertia::render('dormitory/index', [
            'filters' => ['lembaga' => $organization, 'status' => $includeInactive ? 'semua' : 'aktif'],
            'options' => [
                'owners' => $owners,
                'createOwners' => $directory->ownerOptions($this->tenantId(), $manageable),
                'policies' => YearEndPolicy::options(),
            ],
            'dormitories' => $dormitories,
        ]);
    }

    public function store(Request $request, ManageDormitories $dormitories): RedirectResponse
    {
        $request->validate(array_merge($this->rules(), [
            'organization_id' => ['required', 'uuid'],
        ]), $this->facilityMessages());

        $organization = Organization::query()->find($request->string('organization_id')->value());

        if (! $organization instanceof Organization) {
            abort(404);
        }

        Gate::authorize(DormitoryAccess::FACILITIES_MANAGE, $organization);

        $dormitory = $this->attempt(fn (): Dormitory => $dormitories->create(
            $this->tenantId(),
            $organization->id,
            FacilityCode::clean($request->input('code')),
            FacilityCode::cleanName($request->input('name')),
            YearEndPolicy::from($request->string('default_year_end_policy', YearEndPolicy::Keep->value)->value()),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Asrama {$dormitory->name} ditambahkan."]);

        return redirect()->route('dormitory.dormitories.show', ['dormitory' => $dormitory->id]);
    }

    public function show(Request $request, string $dormitory, FacilityDirectory $directory, PlacementDirectory $placements, SupervisorDirectory $supervisors): Response
    {
        $target = $this->find($dormitory, DormitoryAccess::FACILITIES_VIEW);
        $canViewResidents = $this->authorization->allows(DormitoryAccess::RESIDENTS_VIEW, $target->organization_id);
        $filters = ResidentController::filters($request, [$target->id], $target->id);

        return Inertia::render('dormitory/show', array_merge($directory->dormitory($target), [
            'canManage' => $this->authorization->allows(DormitoryAccess::FACILITIES_MANAGE, $target->organization_id),
            'canViewResidents' => $canViewResidents,
            'residentFilters' => $filters,
            'residents' => $canViewResidents && $request->query('tab') === 'penghuni'
                ? $placements->search($this->tenantId(), [$target->organization_id], $filters)
                : null,
            // Tab Wali kamar (DM-3): room_id → wali kamar aktif; dimuat saat tab dibuka.
            'supervisors' => $request->query('tab') === 'wali-kamar'
                ? $supervisors->forRooms($this->tenantId(), self::roomIds($target->id))
                : null,
            'options' => [
                'genders' => FacilityDirectory::genderOptions(),
                'bases' => CapacityBasis::options(),
                'compositions' => RoomComposition::options(),
                'policies' => YearEndPolicy::options(),
                'supervisorSlotsMax' => ManageRooms::SUPERVISOR_SLOTS_MAX,
                'categories' => PlacementCategory::options(),
            ],
        ]));
    }

    public function update(Request $request, string $dormitory, ManageDormitories $dormitories): RedirectResponse
    {
        $target = $this->find($dormitory, DormitoryAccess::FACILITIES_MANAGE);

        $request->validate($this->rules(), $this->facilityMessages());

        $changed = $this->attempt(fn (): array => $dormitories->update(
            $this->tenantId(),
            $target->id,
            FacilityCode::clean($request->input('code')),
            FacilityCode::cleanName($request->input('name')),
            YearEndPolicy::from($request->string('default_year_end_policy', YearEndPolicy::Keep->value)->value()),
        ));

        return $this->done($changed === [] ? 'Tidak ada data yang berubah.' : 'Asrama disimpan.');
    }

    public function status(Request $request, string $dormitory, ManageDormitories $dormitories): RedirectResponse
    {
        $target = $this->find($dormitory, DormitoryAccess::FACILITIES_MANAGE);

        $request->validate(['active' => ['required', 'boolean']]);

        $updated = $this->attempt(fn (): Dormitory => $dormitories->setActive($this->tenantId(), $target->id, $request->boolean('active')));

        return $this->done($updated->is_active
            ? "Asrama {$updated->name} diaktifkan kembali."
            : "Asrama {$updated->name} dinonaktifkan. Gedung dan kamarnya tidak menerima penghuni baru.");
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function find(string $dormitoryId, string $permission): Dormitory
    {
        $dormitory = Str::isUuid($dormitoryId) ? Dormitory::query()->find($dormitoryId) : null;

        if (! $dormitory instanceof Dormitory) {
            abort(404);
        }

        Gate::authorize($permission, $dormitory);

        return $dormitory;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:'.ManageDormitories::NAME_MAX_LENGTH],
            'default_year_end_policy' => ['nullable', 'string', Rule::enum(YearEndPolicy::class)],
        ];
    }

    /**
     * @return list<string>
     */
    private static function roomIds(string $dormitoryId): array
    {
        return array_values(array_map('strval', Room::query()->where('dormitory_id', $dormitoryId)->pluck('id')->all()));
    }
}
