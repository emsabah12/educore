<?php

namespace Modules\HR\Application\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Attendance\AttendanceStatus;
use Modules\HR\Domain\Attendance\Exceptions\AttendanceException;
use Modules\HR\Domain\Leave\LeaveStatus;
use stdClass;

/**
 * Lembar presensi pegawai per lembaga & tanggal oleh petugas (PRD-001 §14.3, HR-4b).
 *
 * Aturan [ASUMSI, disetujui 2026-10-22; rincian 2026-10-24]:
 * - Isi lembar = pegawai yang penempatan UTAMA-nya di lembaga itu pada tanggal itu.
 * - Pegawai yang cuti disetujui hari itu tampil "Cuti" dan tidak bisa diisi (cuti dari data cuti).
 * - Status kosong = "Belum diisi"; mengosongkan baris yang sudah terisi menghapus catatannya.
 * - Jam hanya untuk Hadir & Dinas luar; terlambat/pulang cepat dihitung untuk Hadir saja.
 * - Jam yang tidak diubah petugas tetap bertanda "mandiri"; yang diubah/diisi petugas bertanda petugas.
 * - Petugas lembaga: hari ini s.d. 6 hari sebelumnya; pemegang izin tingkat Yayasan: tanggal mana pun;
 *   keduanya tidak di masa depan. Hak akses lembaga dicek pemanggil.
 * - Audit per baris yang berubah, tanpa isi keterangan.
 *
 * @phpstan-import-type Expected from ExpectedHours
 *
 * @phpstan-type SheetRecord array{status: string, status_label: string, check_in: string|null, check_out: string|null, check_in_source: string|null, check_out_source: string|null, late: int, early: int, note: string|null, updated_by: string|null}
 * @phpstan-type SheetRow array{employee_id: string, name: string, employee_number: string|null, expected: array{required: bool, label: string}, leave: string|null, record: SheetRecord|null}
 */
final class AttendanceSheet
{
    /** Petugas lembaga boleh mengisi/mengoreksi hari ini dan 6 hari sebelumnya. */
    public const OFFICER_DAYS = 7;

    public const NOTE_MAX_LENGTH = 300;

    public function __construct(
        private readonly ExpectedHours $expected,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Tanggal paling awal yang boleh diisi pengguna ini, atau null = bebas.
     */
    public static function earliest(bool $tenantWide): ?string
    {
        return $tenantWide ? null : BusinessClock::today()->subDays(self::OFFICER_DAYS - 1)->toDateString();
    }

    /**
     * @return list<SheetRow>
     */
    public function rows(string $tenantId, string $organizationId, string $date): array
    {
        $employees = $this->employees($tenantId, $organizationId, $date);

        if ($employees === []) {
            return [];
        }

        $ids = array_keys($employees);
        $expected = $this->expected->forEmployees($tenantId, array_fill_keys($ids, $organizationId), $date, $date);
        $leaves = $this->leaves($ids, $date);
        $records = DB::table('hr_attendances as a')
            ->leftJoin('memberships as m', 'm.id', '=', 'a.updated_by_membership_id')
            ->leftJoin('persons as p', 'p.id', '=', 'm.person_id')
            ->whereIn('a.employee_id', $ids)
            ->where('a.date', $date)
            ->get(['a.*', 'p.name as updated_by'])
            ->keyBy('employee_id');

        $rows = [];

        foreach ($employees as $id => $employee) {
            $record = $records->get($id);
            $day = $expected[$id][$date] ?? null;

            $rows[] = [
                'employee_id' => $id,
                'name' => $employee['name'],
                'employee_number' => $employee['number'],
                'expected' => ['required' => $day['required'] ?? false, 'label' => $day['label'] ?? '—'],
                'leave' => $leaves[$id] ?? null,
                'record' => $record instanceof stdClass ? self::present($record) : null,
            ];
        }

        return $rows;
    }

    /**
     * Simpan lembar (hanya baris yang berubah).
     *
     * @param  array<string, array{status: mixed, check_in: mixed, check_out: mixed, note: mixed}>  $input  pegawai => isian
     * @return array{saved: int, removed: int}
     *
     * @throws AttendanceException
     */
    public function save(string $tenantId, string $organizationId, string $date, bool $tenantWide, string $membershipId, array $input): array
    {
        $this->assertDate($date, $tenantWide);

        return DB::transaction(function () use ($tenantId, $organizationId, $date, $membershipId, $input): array {
            $employees = $this->employees($tenantId, $organizationId, $date);

            foreach (array_keys($input) as $employeeId) {
                if (! isset($employees[$employeeId])) {
                    throw AttendanceException::make('form', 'Daftar pegawai lembaga ini berubah sejak halaman dibuka. Muat ulang halaman lalu isi lagi.');
                }
            }

            $ids = array_keys($employees);
            $expected = $this->expected->forEmployees($tenantId, array_fill_keys($ids, $organizationId), $date, $date);
            $leaves = $this->leaves($ids, $date);
            $existing = DB::table('hr_attendances')->whereIn('employee_id', $ids)->where('date', $date)->lockForUpdate()->get()->keyBy('employee_id');
            $counts = ['saved' => 0, 'removed' => 0];

            foreach ($input as $employeeId => $values) {
                // Pegawai cuti: tidak diisi dari lembar (cuti tampil dari data cuti).
                if (isset($leaves[$employeeId])) {
                    continue;
                }

                $row = $this->parse($employeeId, $values);
                $current = $existing->get($employeeId);
                $current = $current instanceof stdClass ? $current : null;

                if ($row === null) {
                    if ($current !== null) {
                        DB::table('hr_attendances')->where('id', $current->id)->delete();
                        $this->log($tenantId, $organizationId, $employeeId, $date, 'Dihapus', null, null, true);
                        $counts['removed']++;
                    }

                    continue;
                }

                $day = $expected[$employeeId][$date] ?? null;

                if ($this->write($tenantId, $organizationId, $employeeId, $date, $row, $day, $current, $membershipId)) {
                    $counts['saved']++;
                }
            }

            return $counts;
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @throws AttendanceException
     */
    private function assertDate(string $date, bool $tenantWide): void
    {
        if (! CarbonImmutable::hasFormat($date, 'Y-m-d')) {
            throw AttendanceException::make('date', 'Tanggal tidak valid.');
        }

        if ($date > BusinessClock::todayString()) {
            throw AttendanceException::make('date', 'Presensi tidak bisa diisi untuk tanggal yang akan datang.');
        }

        $earliest = self::earliest($tenantWide);

        if ($earliest !== null && $date < $earliest) {
            throw AttendanceException::make('date', 'Petugas lembaga hanya bisa mengisi atau mengoreksi '.self::OFFICER_DAYS.' hari terakhir. Hubungi Admin SDM untuk tanggal yang lebih lama.');
        }
    }

    /**
     * Isian satu baris: null = kosong (belum diisi).
     *
     * @param  array{status: mixed, check_in: mixed, check_out: mixed, note: mixed}  $values
     * @return array{status: AttendanceStatus, check_in: string|null, check_out: string|null, note: string|null}|null
     *
     * @throws AttendanceException
     */
    private function parse(string $employeeId, array $values): ?array
    {
        $field = fn (string $name): string => "rows.{$employeeId}.{$name}";
        $text = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        $statusText = $text($values['status']);

        if ($statusText === null) {
            return null;
        }

        $status = AttendanceStatus::tryFrom($statusText) ?? throw AttendanceException::make($field('status'), 'Pilih status dari daftar.');
        $checkIn = $text($values['check_in']);
        $checkOut = $text($values['check_out']);
        $note = $text($values['note']);

        foreach (['check_in' => $checkIn, 'check_out' => $checkOut] as $name => $time) {
            if ($time !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                throw AttendanceException::make($field($name), 'Jam tidak valid (JJ:MM).');
            }
        }

        if (! $status->hasTimes() && ($checkIn !== null || $checkOut !== null)) {
            throw AttendanceException::make($field('check_in'), 'Jam hanya diisi untuk Hadir atau Dinas luar.');
        }

        if ($checkOut !== null && $checkIn === null) {
            throw AttendanceException::make($field('check_in'), 'Isi jam masuk sebelum jam pulang.');
        }

        if ($checkIn !== null && $checkOut !== null && $checkOut < $checkIn) {
            throw AttendanceException::make($field('check_out'), 'Jam pulang tidak boleh sebelum jam masuk.');
        }

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw AttendanceException::make($field('note'), 'Keterangan maksimal '.self::NOTE_MAX_LENGTH.' karakter.');
        }

        return ['status' => $status, 'check_in' => $checkIn, 'check_out' => $checkOut, 'note' => $note];
    }

    /**
     * @param  array{status: AttendanceStatus, check_in: string|null, check_out: string|null, note: string|null}  $row
     * @param  Expected|null  $day
     * @return bool true bila ada perubahan
     */
    private function write(string $tenantId, string $organizationId, string $employeeId, string $date, array $row, ?array $day, ?stdClass $current, string $membershipId): bool
    {
        $short = fn (mixed $time): ?string => $time === null ? null : substr((string) $time, 0, 5);
        $unchanged = $current !== null
            && $current->status === $row['status']->value
            && $short($current->check_in) === $row['check_in']
            && $short($current->check_out) === $row['check_out']
            && ($current->note === null ? null : (string) $current->note) === $row['note'];

        if ($unchanged) {
            return false;
        }

        $required = $day !== null && $day['required'];
        $start = $required ? $day['start'] : null;
        $end = $required ? $day['end'] : null;
        $present = $row['status'] === AttendanceStatus::Present;
        // Jam yang sama dengan sebelumnya tetap bertanda sumber lamanya (mis. absen mandiri).
        $source = fn (string $column, ?string $time): ?string => $time === null
            ? null
            : ($current !== null && $short($current->{$column}) === $time ? (string) $current->{$column.'_source'} : ManageAttendance::SOURCE_OFFICER);

        $values = [
            'status' => $row['status']->value,
            'check_in' => $row['check_in'] === null ? null : $row['check_in'].':00',
            'check_out' => $row['check_out'] === null ? null : $row['check_out'].':00',
            'check_in_source' => $source('check_in', $row['check_in']),
            'check_out_source' => $source('check_out', $row['check_out']),
            'expected_start' => $start === null ? null : $start.':00',
            'expected_end' => $end === null ? null : $end.':00',
            'late_minutes' => $present && $row['check_in'] !== null ? ExpectedHours::lateMinutes($start, $day['tolerance'] ?? 0, $row['check_in']) : 0,
            'early_minutes' => $present && $row['check_out'] !== null ? ExpectedHours::earlyMinutes($end, $row['check_out']) : 0,
            'note' => $row['note'],
            'updated_by_membership_id' => $membershipId,
            'updated_at' => CarbonImmutable::now(),
        ];

        if ($current === null) {
            DB::table('hr_attendances')->insert($values + [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'employee_id' => $employeeId,
                'organization_id' => $organizationId,
                'date' => $date,
                'created_at' => CarbonImmutable::now(),
            ]);
        } else {
            DB::table('hr_attendances')->where('id', $current->id)->update($values);
        }

        $this->log($tenantId, $organizationId, $employeeId, $date, $row['status']->label(), $row['check_in'], $row['check_out'], $current !== null, $values['late_minutes']);

        return true;
    }

    private function log(string $tenantId, string $organizationId, string $employeeId, string $date, string $status, ?string $checkIn, ?string $checkOut, bool $correction, int $late = 0): void
    {
        $this->audit->record(
            HrAuditAction::ATTENDANCE_RECORDED,
            HrAuditAction::SUBJECT_EMPLOYEE,
            $employeeId,
            [
                'tanggal' => $date,
                'status' => $status,
                'jam_masuk' => $checkIn,
                'jam_pulang' => $checkOut,
                'terlambat_menit' => $late,
                'koreksi' => $correction,
            ],
            organizationId: $organizationId,
            tenantId: $tenantId,
        );
    }

    /**
     * Pegawai yang penempatan utamanya di lembaga ini pada tanggal itu, urut nama.
     *
     * @return array<string, array{name: string, number: string|null}>
     */
    private function employees(string $tenantId, string $organizationId, string $date): array
    {
        $rows = DB::table('hr_placements as pl')
            ->join('hr_employees as e', 'e.id', '=', 'pl.employee_id')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->where('pl.tenant_id', $tenantId)
            ->where('pl.organization_id', $organizationId)
            ->where('pl.is_primary', true)
            ->where('pl.start_date', '<=', $date)
            ->where(fn (Builder $query) => $query->whereNull('pl.end_date')->orWhere('pl.end_date', '>=', $date))
            ->orderBy('p.name')
            ->orderBy('e.id')
            ->get(['e.id', 'e.employee_number', 'p.name']);

        $employees = [];

        foreach ($rows as $row) {
            $employees[(string) $row->id] = ['name' => (string) $row->name, 'number' => $row->employee_number === null ? null : (string) $row->employee_number];
        }

        return $employees;
    }

    /**
     * Jenis cuti disetujui per pegawai pada tanggal itu.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, string>
     */
    private function leaves(array $employeeIds, string $date): array
    {
        $rows = DB::table('hr_leave_requests as r')
            ->join('hr_leave_types as t', fn (JoinClause $join) => $join->on('t.id', '=', 'r.leave_type_id'))
            ->whereIn('r.employee_id', $employeeIds)
            ->where('r.status', LeaveStatus::Approved->value)
            ->where('r.start_date', '<=', $date)
            ->where('r.end_date', '>=', $date)
            ->get(['r.employee_id', 't.name']);

        $leaves = [];

        foreach ($rows as $row) {
            $leaves[(string) $row->employee_id] = (string) $row->name;
        }

        return $leaves;
    }

    /**
     * @return SheetRecord
     */
    private static function present(stdClass $record): array
    {
        $status = AttendanceStatus::from((string) $record->status);
        $time = fn (mixed $value): ?string => $value === null ? null : substr((string) $value, 0, 5);

        return [
            'status' => $status->value,
            'status_label' => $status->label(),
            'check_in' => $time($record->check_in),
            'check_out' => $time($record->check_out),
            'check_in_source' => $record->check_in_source === null ? null : (string) $record->check_in_source,
            'check_out_source' => $record->check_out_source === null ? null : (string) $record->check_out_source,
            'late' => (int) $record->late_minutes,
            'early' => (int) $record->early_minutes,
            'note' => $record->note === null ? null : (string) $record->note,
            'updated_by' => $record->updated_by === null ? null : (string) $record->updated_by,
        ];
    }
}
