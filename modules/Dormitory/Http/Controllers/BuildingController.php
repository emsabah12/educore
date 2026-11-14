<?php

namespace Modules\Dormitory\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Core\Domain\Person\Gender;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\Facility\FacilityCode;
use Modules\Dormitory\Application\Facility\ManageBuildings;
use Modules\Dormitory\Domain\Facility\Building;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Http\Controllers\Concerns\HandlesFacilityActions;

/**
 * Gedung asrama (PRD-008 §4.1–§4.2, §15 DM-1): tambah, ubah, nonaktifkan/aktifkan kembali — dari halaman detail
 * asrama. Butuh `dormitory.facilities.manage` di node pemilik asrama.
 */
final class BuildingController
{
    use HandlesFacilityActions;

    public function store(Request $request, string $dormitory, ManageBuildings $buildings): RedirectResponse
    {
        $target = Str::isUuid($dormitory) ? Dormitory::query()->find($dormitory) : null;

        if (! $target instanceof Dormitory) {
            abort(404);
        }

        Gate::authorize(DormitoryAccess::FACILITIES_MANAGE, $target);

        $request->validate($this->rules(), $this->facilityMessages());

        $building = $this->attempt(fn (): Building => $buildings->create(
            $this->tenantId(),
            $target->id,
            FacilityCode::clean($request->input('code')),
            FacilityCode::cleanName($request->input('name')),
            Gender::from($request->string('gender')->value()),
        ));

        return $this->done("Gedung {$building->name} ditambahkan.");
    }

    public function update(Request $request, string $building, ManageBuildings $buildings): RedirectResponse
    {
        $target = $this->find($building);

        $request->validate($this->rules(), $this->facilityMessages());

        $changed = $this->attempt(fn (): array => $buildings->update(
            $this->tenantId(),
            $target->id,
            FacilityCode::clean($request->input('code')),
            FacilityCode::cleanName($request->input('name')),
            Gender::from($request->string('gender')->value()),
        ));

        return $this->done($changed === [] ? 'Tidak ada data yang berubah.' : 'Gedung disimpan.');
    }

    public function status(Request $request, string $building, ManageBuildings $buildings): RedirectResponse
    {
        $target = $this->find($building);

        $request->validate(['active' => ['required', 'boolean']]);

        $updated = $this->attempt(fn (): Building => $buildings->setActive($this->tenantId(), $target->id, $request->boolean('active')));

        return $this->done($updated->is_active
            ? "Gedung {$updated->name} diaktifkan kembali."
            : "Gedung {$updated->name} dinonaktifkan. Kamarnya tidak menerima penghuni baru.");
    }

    private function find(string $buildingId): Building
    {
        $building = Str::isUuid($buildingId) ? Building::query()->find($buildingId) : null;

        if (! $building instanceof Building) {
            abort(404);
        }

        Gate::authorize(DormitoryAccess::FACILITIES_MANAGE, $building);

        return $building;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:'.ManageBuildings::NAME_MAX_LENGTH],
            'gender' => ['required', 'string', Rule::enum(Gender::class)],
        ];
    }
}
