<?php

namespace Modules\Dormitory\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Dormitory\Application\Attendance\ManageAttendanceKinds;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Domain\Attendance\AttendanceKind;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Http\Controllers\Concerns\HandlesFacilityActions;

/**
 * Jenis absen per asrama (PRD-008 §22.1), dikelola di tab Pengaturan detail asrama oleh pemilik
 * `dormitory.facilities.manage` [ASUMSI disetujui]. Di luar cakupan 404, tanpa izin 403.
 */
final class AttendanceKindController
{
    use HandlesFacilityActions;

    public function __construct(private readonly ManageAttendanceKinds $kinds) {}

    public function store(Request $request, string $dormitory): RedirectResponse
    {
        $target = $this->dormitory($dormitory);
        $name = (string) $request->string('name');
        $kind = $this->attempt(fn () => $this->kinds->create($this->tenantId(), $target->id, $name));

        return $this->done("Jenis absen {$kind->name} ditambahkan.");
    }

    public function update(Request $request, string $kind): RedirectResponse
    {
        $target = $this->kind($kind);
        $this->attempt(fn () => $this->kinds->rename($this->tenantId(), $target->id, (string) $request->string('name')));

        return $this->done('Jenis absen diubah.');
    }

    public function status(Request $request, string $kind): RedirectResponse
    {
        $target = $this->kind($kind);
        $active = $request->boolean('active');
        $this->attempt(fn () => $this->kinds->setActive($this->tenantId(), $target->id, $active));

        return $this->done($active ? "Jenis absen {$target->name} diaktifkan kembali." : "Jenis absen {$target->name} dinonaktifkan.");
    }

    public function move(Request $request, string $kind): RedirectResponse
    {
        $target = $this->kind($kind);
        $this->attempt(fn () => $this->kinds->move($this->tenantId(), $target->id, $request->input('direction') === 'up' ? -1 : 1));

        return back();
    }

    public function destroy(string $kind): RedirectResponse
    {
        $target = $this->kind($kind);
        $this->attempt(fn () => $this->kinds->delete($this->tenantId(), $target->id));

        return $this->done("Jenis absen {$target->name} dihapus.");
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    private function dormitory(string $id): Dormitory
    {
        $dormitory = Str::isUuid($id) ? Dormitory::query()->find($id) : null;

        if (! $dormitory instanceof Dormitory) {
            abort(404);
        }

        Gate::authorize(DormitoryAccess::FACILITIES_MANAGE, $dormitory);

        return $dormitory;
    }

    private function kind(string $id): AttendanceKind
    {
        $kind = Str::isUuid($id) ? AttendanceKind::query()->find($id) : null;

        if (! $kind instanceof AttendanceKind) {
            abort(404);
        }

        Gate::authorize(DormitoryAccess::FACILITIES_MANAGE, $kind);

        return $kind;
    }
}
