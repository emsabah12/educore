<?php

namespace Modules\Dormitory\Application\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\Dormitory\Application\Audit\DormitoryAuditAction;
use Modules\Dormitory\Domain\Attendance\AttendanceException;
use Modules\Dormitory\Domain\Attendance\AttendanceKind;
use Modules\Dormitory\Domain\Attendance\AttendanceSession;
use Modules\Dormitory\Domain\Attendance\AttendanceStatus;
use Modules\Dormitory\Domain\Facility\Room;
use Modules\Dormitory\Domain\Placement\PlacementCategory;
use Modules\Dormitory\Domain\Placement\PlacementStatus;

/**
 * Absen asrama / roll call per kamar (PRD-008 §12 DM-7, §22.2).
 *
 * Aturan [OWNER/ASUMSI disetujui 2026-11-16]:
 * - Satu sesi = kamar + tanggal + jenis absen (jenis aktif milik asrama kamar itu).
 * - Yang diabsen = penghuni (santri & pengawas) yang Aktif di kamar itu pada tanggal tersebut: masuk ≤ tanggal dan
 *   belum keluar pada tanggal itu (mutasi di tanggal X = sudah di kamar baru pada tanggal X). Rencana tidak.
 * - Status H/I/S/T; keterangan opsional maks. 200 karakter (isinya tidak masuk audit). Simpan semua sekaligus.
 * - Wali kamar: hari ini s.d. 6 hari sebelumnya (7 hari). Pengurus (`attendance.manage`): s.d. 1 tahun ke belakang.
 *   Tidak di masa depan.
 * - Hak akses (wali kamar aktif kamar itu, atau `attendance.manage` di pemilik asrama) dicek pemanggil.
 *
 * @phpstan-type RosterRow array{placement_id: string, membership_id: string, name: string, category: string, category_label: string, bed: string|null}
 * @phpstan-type SheetRow array{placement_id: string, membership_id: string, name: string, category: string, category_label: string, bed: string|null, status: string, note: string|null}
 * @phpstan-type Window array{min: string, max: string}
 */
final class ManageDormitoryAttendance
{
    public const NOTE_MAX_LENGTH = 200;

    /** Wali kamar boleh mengisi/mengoreksi hari ini dan 6 hari sebelumnya. */
    public const SUPERVISOR_DAYS = 7;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return Window
     */
    public static function window(bool $asSupervisor): array
    {
        $today = BusinessClock::today();
        $min = $asSupervisor ? $today->subDays(self::SUPERVISOR_DAYS - 1) : $today->subYear();

        return ['min' => $min->toDateString(), 'max' => $today->toDateString()];
    }

    /**
     * Tanggal dari alamat bila sah dan di dalam rentang; selain itu hari ini.
     *
     * @param  Window  $window
     */
    public static function pickDate(mixed $requested, array $window): string
    {
        return is_string($requested) && self::isDate($requested) && $requested >= $window['min'] && $requested <= $window['max']
            ? $requested
            : $window['max'];
    }

    /**
     * Lembar absen: penghuni kamar pada tanggal itu beserta status tersimpan (bawaan Hadir).
     *
     * @return array{rows: list<SheetRow>, session: array{recorded_by: string|null, updated_at: string|null}|null}
     */
    public function sheet(string $roomId, string $kindId, string $date): array
    {
        $session = $this->session($roomId, $kindId, $date);
        $saved = [];

        if ($session !== null) {
            foreach (DB::table('dm_attendance_records')->where('session_id', $session->id)->get(['placement_id', 'status', 'note']) as $record) {
                $saved[(string) $record->placement_id] = ['status' => (string) $record->status, 'note' => $record->note === null ? null : (string) $record->note];
            }
        }

        $rows = [];

        foreach ($this->roster($roomId, $date) as $row) {
            $rows[] = $row + [
                'status' => $saved[$row['placement_id']]['status'] ?? AttendanceStatus::Present->value,
                'note' => $saved[$row['placement_id']]['note'] ?? null,
            ];
        }

        return [
            'rows' => $rows,
            'session' => $session === null ? null : [
                'recorded_by' => $session->recorded_by === null ? null : $this->memberName($session->recorded_by),
                'updated_at' => $session->updated_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Ringkasan satu tanggal + jenis untuk banyak kamar: penghuni pada tanggal itu dan jumlah per status bila sudah
     * diisi.
     *
     * @param  list<string>  $roomIds
     * @return array<string, array{residents: int, filled: bool, counts: array{H: int, I: int, S: int, T: int}, recorded_by: string|null, updated_at: string|null}>
     */
    public function overview(array $roomIds, string $kindId, string $date): array
    {
        if ($roomIds === []) {
            return [];
        }

        $result = [];

        foreach ($roomIds as $roomId) {
            $result[$roomId] = ['residents' => 0, 'filled' => false, 'counts' => ['H' => 0, 'I' => 0, 'S' => 0, 'T' => 0], 'recorded_by' => null, 'updated_at' => null];
        }

        foreach ($this->rosterQuery($roomIds, $date)->groupBy('pl.room_id')->selectRaw('pl.room_id, count(*) as total')->get() as $row) {
            if (isset($result[(string) $row->room_id])) {
                $result[(string) $row->room_id]['residents'] = (int) $row->total;
            }
        }

        $sessions = AttendanceSession::query()->withoutGlobalScope('tenant')
            ->whereIn('room_id', $roomIds)
            ->where('kind_id', $kindId)
            ->whereDate('date', $date)
            ->get();
        $names = $this->memberNames(array_values(array_unique(array_map('strval', array_filter($sessions->pluck('recorded_by')->all())))));

        foreach ($sessions as $session) {
            if (isset($result[$session->room_id])) {
                $result[$session->room_id]['filled'] = true;
                $result[$session->room_id]['recorded_by'] = $session->recorded_by === null ? null : ($names[$session->recorded_by] ?? null);
                $result[$session->room_id]['updated_at'] = $session->updated_at?->toIso8601String();
            }
        }

        if ($sessions->isNotEmpty()) {
            foreach (DB::table('dm_attendance_records as ar')
                ->join('dm_attendance_sessions as s', 's.id', '=', 'ar.session_id')
                ->whereIn('ar.session_id', $sessions->modelKeys())
                ->groupBy('s.room_id', 'ar.status')
                ->selectRaw('s.room_id, ar.status, count(*) as total')
                ->get() as $row) {
                $status = AttendanceStatus::tryFrom((string) $row->status);

                if ($status !== null && isset($result[(string) $row->room_id])) {
                    $result[(string) $row->room_id]['counts'][$status->value] = (int) $row->total;
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array{status: mixed, note: mixed}>  $entries  id penempatan => isian
     * @return array{H: int, I: int, S: int, T: int}
     *
     * @throws AttendanceException
     */
    public function save(string $tenantId, string $roomId, string $kindId, string $date, bool $asSupervisor, string $membershipId, array $entries): array
    {
        try {
            return DB::transaction(function () use ($tenantId, $roomId, $kindId, $date, $asSupervisor, $membershipId, $entries): array {
                $room = Str::isUuid($roomId)
                    ? Room::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($roomId)->lockForUpdate()->first()
                    : null;

                if (! $room instanceof Room) {
                    throw AttendanceException::make('form', 'Kamar tidak ditemukan di yayasan ini.');
                }

                $kind = Str::isUuid($kindId)
                    ? AttendanceKind::query()->withoutGlobalScope('tenant')->where('dormitory_id', $room->dormitory_id)->whereKey($kindId)->first()
                    : null;

                if (! $kind instanceof AttendanceKind || ! $kind->is_active) {
                    throw AttendanceException::make('kind', 'Pilih jenis absen yang aktif di asrama ini.');
                }

                $this->assertDate($date, $asSupervisor);
                $rows = $this->validatedRows($this->roster($room->id, $date), $entries);

                $session = $this->session($room->id, $kind->id, $date, lock: true);
                $correction = $session !== null;

                if ($session === null) {
                    $session = new AttendanceSession;
                    $session->fill([
                        'tenant_id' => $tenantId,
                        'organization_id' => $room->organization_id,
                        'dormitory_id' => $room->dormitory_id,
                        'room_id' => $room->id,
                        'kind_id' => $kind->id,
                        'date' => $date,
                    ]);
                }

                $session->recorded_by = $membershipId;
                $session->updated_at = CarbonImmutable::now();
                $session->save();

                DB::table('dm_attendance_records')->where('session_id', $session->id)->delete();
                $now = now();
                $counts = ['H' => 0, 'I' => 0, 'S' => 0, 'T' => 0];
                $insert = [];

                foreach ($rows as $placementId => $row) {
                    $counts[$row['status']->value]++;
                    $insert[] = [
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'session_id' => $session->id,
                        'placement_id' => $placementId,
                        'membership_id' => $row['membership_id'],
                        'status' => $row['status']->value,
                        'note' => $row['note'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('dm_attendance_records')->insert($insert);

                // Keterangan bisa memuat hal pribadi (alasan sakit/izin): audit hanya mencatat jumlah.
                $this->audit->record(
                    DormitoryAuditAction::ATTENDANCE_RECORDED,
                    DormitoryAuditAction::SUBJECT_ROOM,
                    $room->id,
                    [
                        'kamar' => $room->code,
                        'jenis' => $kind->name,
                        'tanggal' => $date,
                        'hadir' => $counts['H'],
                        'izin' => $counts['I'],
                        'sakit' => $counts['S'],
                        'tidak_ada' => $counts['T'],
                        'koreksi' => $correction,
                    ],
                    organizationId: $room->organization_id,
                    tenantId: $tenantId,
                );

                return $counts;
            });
        } catch (UniqueConstraintViolationException) {
            // Dua orang menyimpan absen pertama sesi yang sama bersamaan.
            throw AttendanceException::make('form', 'Absen ini baru saja disimpan orang lain. Muat ulang halaman lalu periksa lagi.');
        }
    }

    /**
     * Penghuni (santri & pengawas) yang Aktif di kamar pada tanggal itu.
     *
     * @return list<RosterRow>
     */
    public function roster(string $roomId, string $date): array
    {
        $rows = [];

        foreach ($this->rosterQuery([$roomId], $date)
            ->join('memberships as m', 'm.id', '=', 'pl.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->leftJoin('dm_beds as b', 'b.id', '=', 'pl.bed_id')
            ->orderByRaw('case when pl.category = ? then 0 else 1 end', [PlacementCategory::Resident->value])
            ->orderBy('p.name')
            ->orderBy('pl.id')
            ->get(['pl.id', 'pl.membership_id', 'pl.category', 'p.name', 'b.code as bed']) as $row) {
            $category = PlacementCategory::from((string) $row->category);
            $rows[] = [
                'placement_id' => (string) $row->id,
                'membership_id' => (string) $row->membership_id,
                'name' => (string) $row->name,
                'category' => $category->value,
                'category_label' => $category->label(),
                'bed' => $row->bed === null ? null : (string) $row->bed,
            ];
        }

        return $rows;
    }

    public static function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $roomIds
     */
    private function rosterQuery(array $roomIds, string $date): Builder
    {
        return DB::table('dm_placements as pl')
            ->whereIn('pl.room_id', $roomIds)
            ->whereIn('pl.status', [PlacementStatus::Active->value, PlacementStatus::Ended->value])
            ->where('pl.check_in_on', '<=', $date)
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('pl.check_out_on')->orWhere('pl.check_out_on', '>', $date);
            });
    }

    private function assertDate(string $date, bool $asSupervisor): void
    {
        if (! self::isDate($date)) {
            throw AttendanceException::make('date', 'Tanggal absen tidak valid.');
        }

        $window = self::window($asSupervisor);

        if ($date > $window['max']) {
            throw AttendanceException::make('date', 'Absen tidak bisa diisi untuk tanggal di masa depan.');
        }

        if ($date < $window['min']) {
            throw AttendanceException::make('date', $asSupervisor
                ? 'Wali kamar hanya bisa mengisi atau mengoreksi absen '.self::SUPERVISOR_DAYS.' hari terakhir. Minta pengurus asrama untuk tanggal yang lebih lama.'
                : 'Absen hanya bisa diisi paling jauh 1 tahun ke belakang.');
        }
    }

    /**
     * @param  list<RosterRow>  $roster
     * @param  array<string, array{status: mixed, note: mixed}>  $entries
     * @return array<string, array{membership_id: string, status: AttendanceStatus, note: string|null}>
     */
    private function validatedRows(array $roster, array $entries): array
    {
        if ($roster === []) {
            throw AttendanceException::make('form', 'Belum ada penghuni di kamar ini pada tanggal tersebut.');
        }

        $known = [];

        foreach ($roster as $row) {
            $known[$row['placement_id']] = $row;
        }

        foreach (array_keys($entries) as $placementId) {
            if (! isset($known[$placementId])) {
                throw AttendanceException::make('form', 'Daftar penghuni kamar ini berubah sejak halaman dibuka. Muat ulang halaman lalu isi lagi.');
            }
        }

        $rows = [];
        $missing = [];

        foreach ($known as $placementId => $person) {
            $entry = $entries[$placementId] ?? null;
            $status = $entry === null ? null : AttendanceStatus::tryFrom(is_string($entry['status']) ? $entry['status'] : '');

            if ($status === null) {
                $missing[] = $person['name'];

                continue;
            }

            $note = is_string($entry['note'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', (string) $entry['note'])) : '';

            if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
                throw AttendanceException::make('form', "Keterangan {$person['name']} maksimal ".self::NOTE_MAX_LENGTH.' karakter.');
            }

            $rows[$placementId] = ['membership_id' => $person['membership_id'], 'status' => $status, 'note' => $note === '' ? null : $note];
        }

        if ($missing !== []) {
            throw AttendanceException::make('form', 'Pilih status kehadiran untuk: '.implode(', ', $missing).'.');
        }

        return $rows;
    }

    private function session(string $roomId, string $kindId, string $date, bool $lock = false): ?AttendanceSession
    {
        if (! Str::isUuid($kindId)) {
            return null;
        }

        $query = AttendanceSession::query()->withoutGlobalScope('tenant')
            ->where('room_id', $roomId)
            ->where('kind_id', $kindId)
            ->whereDate('date', $date);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function memberName(string $membershipId): ?string
    {
        return $this->memberNames([$membershipId])[$membershipId] ?? null;
    }

    /**
     * @param  list<string>  $membershipIds
     * @return array<string, string>
     */
    private function memberNames(array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        $names = [];

        foreach (DB::table('memberships as m')->join('persons as p', 'p.id', '=', 'm.person_id')->whereIn('m.id', $membershipIds)->get(['m.id', 'p.name']) as $row) {
            $names[(string) $row->id] = (string) $row->name;
        }

        return $names;
    }
}
