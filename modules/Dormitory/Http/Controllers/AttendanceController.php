<?php

namespace Modules\Dormitory\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Dormitory\Application\Attendance\AttendanceRecap;
use Modules\Dormitory\Application\Attendance\AttendanceRecapExport;
use Modules\Dormitory\Application\Attendance\ManageAttendanceKinds;
use Modules\Dormitory\Application\Attendance\ManageDormitoryAttendance;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\Facility\FacilityDirectory;
use Modules\Dormitory\Application\Supervision\SupervisorDirectory;
use Modules\Dormitory\Domain\Attendance\AttendanceStatus;
use Modules\Dormitory\Domain\Facility\Building;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Http\Controllers\Concerns\HandlesFacilityActions;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Absen asrama (PRD-008 §12 DM-7, §22).
 *
 * - `/asrama/absen`: per asrama — tab **Isi absen** (semua kamar untuk satu tanggal + jenis, sudah/belum diisi) dan
 *   **Rekap bulanan**. Untuk pemilik `attendance.manage` atau `residents.view`; hanya yang pertama bisa mengisi.
 * - `/asrama/kamar/{room}/absen`: lembar absen satu kamar. Pengurus (`attendance.manage` di pemilik, s.d. 1 tahun
 *   ke belakang) atau wali kamar aktif kamar itu (7 hari terakhir).
 * - `/kamar-saya/{room}/rekap-absen`: rekap bulanan kamar yang diawasi wali kamar.
 */
final class AttendanceController
{
    use HandlesFacilityActions;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ManageAttendanceKinds $kinds,
        private readonly ManageDormitoryAttendance $attendance,
        private readonly SupervisorDirectory $supervisors,
    ) {}

    public function index(Request $request, AttendanceRecap $recap): Response
    {
        $options = $this->dormitoryOptions();

        if ($options === []) {
            Gate::authorize(DormitoryAccess::ATTENDANCE_MANAGE);
        }

        $ids = array_column($options, 'value');
        $requested = $request->query('asrama');
        $dormitory = $options === [] ? null : Dormitory::query()->findOrFail(is_string($requested) && in_array($requested, $ids, true) ? $requested : $ids[0]);
        $tab = $request->query('tab') === 'rekap' ? 'rekap' : 'isi';
        $canFill = $dormitory !== null && $this->authorization->allows(DormitoryAccess::ATTENDANCE_MANAGE, $dormitory->organization_id);
        $kinds = $dormitory === null ? [] : $this->kinds->forDormitory($dormitory->id, activeOnly: true);
        $kindIds = array_column($kinds, 'id');

        $props = [
            'tab' => $tab,
            'options' => ['dormitories' => $options],
            'dormitory' => $dormitory === null ? null : ['id' => $dormitory->id, 'code' => $dormitory->code, 'name' => $dormitory->name],
            'kinds' => $kinds,
            'canFill' => $canFill,
            'canManageKinds' => $dormitory !== null && $this->authorization->allows(DormitoryAccess::FACILITIES_MANAGE, $dormitory->organization_id),
            'fill' => null,
            'recap' => null,
        ];

        if ($dormitory !== null && $tab === 'isi') {
            $window = ManageDormitoryAttendance::window(asSupervisor: false);
            $date = ManageDormitoryAttendance::pickDate($request->query('tanggal'), $window);
            $kindId = is_string($request->query('jenis')) && in_array($request->query('jenis'), $kindIds, true) ? (string) $request->query('jenis') : ($kindIds[0] ?? null);
            $rooms = $this->roomsOf($dormitory->id);
            $overview = $kindId === null ? [] : $this->attendance->overview(array_column($rooms, 'id'), $kindId, $date);
            $rows = [];

            foreach ($rooms as $room) {
                $state = $overview[$room['id']] ?? null;

                // Kamar nonaktif hanya tampil bila ada penghuni atau sudah diisi.
                if (! $room['is_active'] && ($state === null || ($state['residents'] === 0 && ! $state['filled']))) {
                    continue;
                }

                $rows[] = $room + ['state' => $state];
            }

            $props['fill'] = ['date' => $date, 'window' => $window, 'kind' => $kindId, 'rooms' => $rows];
        }

        if ($dormitory !== null && $tab === 'rekap') {
            $props['recap'] = $this->recapProps($request, $recap, $dormitory, null, $kindIds);
        }

        return Inertia::render('dormitory/attendance', $props);
    }

    public function export(Request $request, AttendanceRecap $recap, AttendanceRecapExport $export): BinaryFileResponse
    {
        $dormitory = $this->findDormitory($request->query('asrama'));
        $kindIds = array_column($this->kinds->forDormitory($dormitory->id), 'id');
        $roomId = $this->roomFilter($request->query('kamar'), $dormitory->id);

        return $this->download($recap, $export, $request, $dormitory, $roomId, $kindIds);
    }

    public function sheet(Request $request, string $room, FacilityDirectory $directory): Response
    {
        [$target, $asSupervisor] = $this->roomAccess($room);
        $dormitory = Dormitory::query()->findOrFail($target->dormitory_id);
        $building = Building::query()->findOrFail($target->building_id);
        $kinds = $this->kinds->forDormitory($dormitory->id, activeOnly: true);
        $kindIds = array_column($kinds, 'id');
        $window = ManageDormitoryAttendance::window($asSupervisor);
        $date = ManageDormitoryAttendance::pickDate($request->query('tanggal'), $window);
        $kindId = is_string($request->query('jenis')) && in_array($request->query('jenis'), $kindIds, true) ? (string) $request->query('jenis') : ($kindIds[0] ?? null);

        return Inertia::render('dormitory/attendance-sheet', [
            'room' => ['id' => $target->id, 'code' => $target->code, 'name' => $target->name, 'building' => $building->name, 'gender_label' => FacilityDirectory::genderLabel($building->gender)],
            'dormitory' => ['id' => $dormitory->id, 'name' => $dormitory->name],
            'asSupervisor' => $asSupervisor,
            'kinds' => $kinds,
            'kind' => $kindId,
            'date' => $date,
            'window' => $window,
            'statuses' => AttendanceStatus::options(),
            'sheet' => $kindId === null ? ['rows' => [], 'session' => null] : $this->attendance->sheet($target->id, $kindId, $date),
        ]);
    }

    public function save(Request $request, string $room): RedirectResponse
    {
        [$target, $asSupervisor] = $this->roomAccess($room);
        $entries = [];

        foreach ((array) $request->input('entries', []) as $placementId => $entry) {
            if (is_string($placementId) && is_array($entry)) {
                $entries[$placementId] = ['status' => $entry['status'] ?? null, 'note' => $entry['note'] ?? null];
            }
        }

        $counts = $this->attempt(fn () => $this->attendance->save(
            $this->tenantId(),
            $target->id,
            (string) $request->string('kind'),
            (string) $request->string('date'),
            $asSupervisor,
            $this->actorId(),
            $entries,
        ));

        return $this->done("Absen kamar {$target->code} disimpan: {$counts['H']} hadir, {$counts['I']} izin, {$counts['S']} sakit, {$counts['T']} tidak ada.");
    }

    public function myRecap(Request $request, string $room, AttendanceRecap $recap): Response
    {
        $target = $this->supervisedRoom($room);
        $dormitory = Dormitory::query()->findOrFail($target->dormitory_id);
        $kinds = $this->kinds->forDormitory($dormitory->id);

        return Inertia::render('dormitory/attendance-recap', [
            'room' => ['id' => $target->id, 'code' => $target->code],
            'dormitory' => ['id' => $dormitory->id, 'name' => $dormitory->name],
            'kinds' => $kinds,
            'recap' => $this->recapProps($request, $recap, $dormitory, $target->id, array_column($kinds, 'id')),
        ]);
    }

    public function myRecapExport(Request $request, string $room, AttendanceRecap $recap, AttendanceRecapExport $export): BinaryFileResponse
    {
        $target = $this->supervisedRoom($room);
        $dormitory = Dormitory::query()->findOrFail($target->dormitory_id);

        return $this->download($recap, $export, $request, $dormitory, $target->id, array_column($this->kinds->forDormitory($dormitory->id), 'id'));
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Asrama yang boleh dibuka di halaman Absen asrama: cakupan `attendance.manage` ∪ `residents.view`.
     *
     * @return list<array{value: string, label: string}>
     */
    private function dormitoryOptions(): array
    {
        $visible = array_values(array_unique(array_merge(
            $this->authorization->visibleNodeIds(DormitoryAccess::ATTENDANCE_MANAGE),
            $this->authorization->visibleNodeIds(DormitoryAccess::RESIDENTS_VIEW),
        )));
        $options = [];

        foreach (Dormitory::query()->whereIn('organization_id', $visible)->orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'is_active']) as $dormitory) {
            $options[] = ['value' => $dormitory->id, 'label' => $dormitory->name.($dormitory->is_active ? '' : ' (nonaktif)')];
        }

        return $options;
    }

    private function findDormitory(mixed $id): Dormitory
    {
        $dormitory = is_string($id) && Str::isUuid($id) ? Dormitory::query()->find($id) : null;

        if (! $dormitory instanceof Dormitory) {
            abort(404);
        }

        if (! $this->authorization->allows(DormitoryAccess::ATTENDANCE_MANAGE, $dormitory->organization_id)) {
            Gate::authorize(DormitoryAccess::RESIDENTS_VIEW, $dormitory);
        }

        return $dormitory;
    }

    /**
     * Kamar asrama, urut gedung lalu kode.
     *
     * @return list<array{id: string, code: string, building: string, is_active: bool}>
     */
    private function roomsOf(string $dormitoryId): array
    {
        $rows = [];

        foreach (Room::query()->join('dm_buildings as g', 'g.id', '=', 'dm_rooms.building_id')
            ->where('dm_rooms.dormitory_id', $dormitoryId)
            ->orderBy('g.code')
            ->orderBy('dm_rooms.code')
            ->get(['dm_rooms.id', 'dm_rooms.code', 'dm_rooms.is_active', 'g.name as building_name', 'g.is_active as building_active']) as $room) {
            $rows[] = [
                'id' => $room->id,
                'code' => $room->code,
                'building' => (string) $room->getAttribute('building_name'),
                'is_active' => $room->is_active && (bool) $room->getAttribute('building_active'),
            ];
        }

        return $rows;
    }

    private function roomFilter(mixed $id, string $dormitoryId): ?string
    {
        return is_string($id) && Str::isUuid($id) && Room::query()->whereKey($id)->where('dormitory_id', $dormitoryId)->exists() ? $id : null;
    }

    /**
     * Lembar absen kamar: pengurus (`attendance.manage` di pemilik) atau wali kamar aktif kamar itu.
     *
     * @return array{0: Room, 1: bool} kamar, dan apakah dibuka sebagai wali kamar (batas 7 hari)
     */
    private function roomAccess(string $id): array
    {
        $room = Str::isUuid($id) ? Room::query()->find($id) : null;

        if (! $room instanceof Room) {
            abort(404);
        }

        if ($this->authorization->allows(DormitoryAccess::ATTENDANCE_MANAGE, $room->organization_id)) {
            return [$room, false];
        }

        if (in_array($room->id, $this->supervisors->roomIdsOf($this->tenantId(), $this->actorId()), true)) {
            return [$room, true];
        }

        Gate::authorize(DormitoryAccess::ATTENDANCE_MANAGE, $room);

        abort(404);
    }

    /** Kamar yang sedang diawasi pengguna sebagai wali kamar; selain itu 404 (pola Kamar saya). */
    private function supervisedRoom(string $id): Room
    {
        $room = Str::isUuid($id) && in_array($id, $this->supervisors->roomIdsOf($this->tenantId(), $this->actorId()), true)
            ? Room::query()->find($id)
            : null;

        if (! $room instanceof Room) {
            abort(404);
        }

        return $room;
    }

    /**
     * @param  list<string>  $kindIds
     * @return array<string, mixed>
     */
    private function recapProps(Request $request, AttendanceRecap $recap, Dormitory $dormitory, ?string $fixedRoomId, array $kindIds): array
    {
        $months = AttendanceRecap::months($request->query('bulan'));
        $kindId = is_string($request->query('jenis')) && in_array($request->query('jenis'), $kindIds, true) ? (string) $request->query('jenis') : null;
        $roomId = $fixedRoomId ?? $this->roomFilter($request->query('kamar'), $dormitory->id);

        return [
            'month' => $months['selected'],
            'months' => $months['options'],
            'kind' => $kindId,
            'room' => $roomId,
            'rooms' => $fixedRoomId === null ? array_map(fn (array $room): array => ['value' => $room['id'], 'label' => $room['code'].' · '.$room['building']], $this->roomsOf($dormitory->id)) : [],
            'data' => $recap->build($dormitory->id, $roomId === null ? null : [$roomId], $months['selected'], $kindId),
        ];
    }

    /**
     * @param  list<string>  $kindIds
     */
    private function download(AttendanceRecap $recap, AttendanceRecapExport $export, Request $request, Dormitory $dormitory, ?string $roomId, array $kindIds): BinaryFileResponse
    {
        $months = AttendanceRecap::months($request->query('bulan'));
        $kindId = is_string($request->query('jenis')) && in_array($request->query('jenis'), $kindIds, true) ? (string) $request->query('jenis') : null;
        $room = $roomId === null ? null : Room::query()->find($roomId);
        $kindName = $kindId === null ? 'semua jenis' : (string) collect($this->kinds->forDormitory($dormitory->id))->firstWhere('id', $kindId)['name'];
        $title = 'Rekap absen '.$dormitory->name.($room === null ? '' : ' — kamar '.$room->code).' — '
            .AttendanceRecap::monthLabel(CarbonImmutable::createFromFormat('!Y-m', $months['selected']) ?: now()->toImmutable()).' ('.$kindName.')';
        $path = tempnam(sys_get_temp_dir(), 'educore-absen-');

        if ($path === false) {
            abort(500, 'File rekap absen tidak bisa dibuat.');
        }

        $export->write($path, $recap->build($dormitory->id, $roomId === null ? null : [$roomId], $months['selected'], $kindId), $title);

        return response()
            ->download($path, AttendanceRecapExport::fileName($dormitory->code, $months['selected'], $room?->code), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }
}
