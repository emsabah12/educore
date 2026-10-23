<?php

namespace Modules\HR\Application\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Attendance\AttendanceStatus;
use Modules\HR\Domain\Attendance\Exceptions\AttendanceException;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Employee\EmployeeStatus;
use Modules\HR\Domain\Employee\Placement;
use Modules\HR\Domain\Leave\LeaveStatus;
use stdClass;

/**
 * Presensi pegawai (PRD-001 §14). HR-4a: absen mandiri — masuk & pulang, hari ini saja, jam server.
 *
 * Aturan [ASUMSI disetujui, 2026-10-22]:
 * - Pegawai aktif dengan penempatan utama; lembaga presensi = penempatan utama.
 * - Absen masuk sekali; absen pulang sekali, sesudah absen masuk. Satuan menit (detik dibuang).
 * - Ditolak bila hari itu tercatat cuti disetujui, atau petugas sudah mengisi status selain Hadir.
 * - Hari tidak wajib tetap boleh absen, tanpa hitungan terlambat/pulang cepat.
 *
 * Hak akses (pegawai itu sendiri) dicek pemanggil.
 */
final class ManageAttendance
{
    public const SOURCE_SELF = 'MANDIRI';

    public const SOURCE_OFFICER = 'PETUGAS';

    public function __construct(
        private readonly ExpectedHours $expected,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{time: string, late: int}
     *
     * @throws AttendanceException
     */
    public function checkIn(string $tenantId, string $employeeId): array
    {
        return DB::transaction(function () use ($tenantId, $employeeId): array {
            [$organizationId, $today, $now] = $this->prepare($tenantId, $employeeId);
            $row = $this->row($employeeId, $today);

            if ($row !== null && $row->check_in !== null) {
                throw AttendanceException::make('form', 'Anda sudah absen masuk hari ini pukul '.self::display((string) $row->check_in).'.');
            }

            if ($row !== null && $row->status !== AttendanceStatus::Present->value) {
                throw AttendanceException::make('form', 'Presensi hari ini sudah diisi petugas: '.AttendanceStatus::from((string) $row->status)->label().'. Hubungi petugas presensi bila keliru.');
            }

            $expected = $this->expected->forEmployee($tenantId, $employeeId, $organizationId, $today, $today)[$today]
                ?? ['required' => false, 'start' => null, 'end' => null, 'tolerance' => 0];
            $start = $expected['required'] ? $expected['start'] : null;
            $late = ExpectedHours::lateMinutes($start, $expected['tolerance'], $now);

            $values = [
                'status' => AttendanceStatus::Present->value,
                'check_in' => $now.':00',
                'check_in_source' => self::SOURCE_SELF,
                'expected_start' => $start === null ? null : $start.':00',
                'expected_end' => $expected['required'] && $expected['end'] !== null ? $expected['end'].':00' : null,
                'late_minutes' => $late,
                'updated_at' => CarbonImmutable::now(),
            ];

            if ($row === null) {
                DB::table('hr_attendances')->insert($values + [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'employee_id' => $employeeId,
                    'organization_id' => $organizationId,
                    'date' => $today,
                    'created_at' => CarbonImmutable::now(),
                ]);
            } else {
                DB::table('hr_attendances')->where('id', $row->id)->update($values);
            }

            $this->audit->record(
                HrAuditAction::ATTENDANCE_CHECKED_IN,
                HrAuditAction::SUBJECT_EMPLOYEE,
                $employeeId,
                ['tanggal' => $today, 'jam' => $now, 'terlambat_menit' => $late],
                organizationId: $organizationId,
                tenantId: $tenantId,
            );

            return ['time' => $now, 'late' => $late];
        });
    }

    /**
     * @return array{time: string, early: int}
     *
     * @throws AttendanceException
     */
    public function checkOut(string $tenantId, string $employeeId): array
    {
        return DB::transaction(function () use ($tenantId, $employeeId): array {
            [$organizationId, $today, $now] = $this->prepare($tenantId, $employeeId);
            $row = $this->row($employeeId, $today);

            if ($row === null || $row->check_in === null) {
                throw AttendanceException::make('form', 'Absen masuk dulu sebelum absen pulang.');
            }

            if ($row->check_out !== null) {
                throw AttendanceException::make('form', 'Anda sudah absen pulang hari ini pukul '.self::display((string) $row->check_out).'.');
            }

            $checkIn = substr((string) $row->check_in, 0, 5);
            // Jam server tidak mundur; pengaman bila jam masuk dikoreksi petugas ke jam yang lebih siang.
            $time = max($now, $checkIn);
            $early = ExpectedHours::earlyMinutes($row->expected_end === null ? null : substr((string) $row->expected_end, 0, 5), $time);

            DB::table('hr_attendances')->where('id', $row->id)->update([
                'check_out' => $time.':00',
                'check_out_source' => self::SOURCE_SELF,
                'early_minutes' => $early,
                'updated_at' => CarbonImmutable::now(),
            ]);

            $this->audit->record(
                HrAuditAction::ATTENDANCE_CHECKED_OUT,
                HrAuditAction::SUBJECT_EMPLOYEE,
                $employeeId,
                ['tanggal' => $today, 'jam' => $time, 'pulang_cepat_menit' => $early],
                organizationId: (string) $row->organization_id,
                tenantId: $tenantId,
            );

            return ['time' => $time, 'early' => $early];
        });
    }

    /**
     * "07:05:00" → "07.05".
     */
    public static function display(string $time): string
    {
        return str_replace(':', '.', substr($time, 0, 5));
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Kunci pegawai (antrean per pegawai), periksa syarat, dan kembalikan lembaga, hari ini, jam kini.
     *
     * @return array{0: string, 1: string, 2: string}
     *
     * @throws AttendanceException
     */
    private function prepare(string $tenantId, string $employeeId): array
    {
        $employee = Employee::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($employeeId)->lockForUpdate()->first();

        if (! $employee instanceof Employee || $employee->status !== EmployeeStatus::Active) {
            throw AttendanceException::make('form', 'Hanya pegawai aktif yang bisa absen.');
        }

        $placement = Placement::query()
            ->withoutGlobalScope('tenant')
            ->where('employee_id', $employee->id)
            ->where('is_primary', true)
            ->whereNull('end_date')
            ->first();

        if (! $placement instanceof Placement) {
            throw AttendanceException::make('form', 'Anda belum punya penempatan utama. Hubungi Admin SDM.');
        }

        $today = BusinessClock::todayString();

        $onLeave = DB::table('hr_leave_requests')
            ->where('employee_id', $employee->id)
            ->where('status', LeaveStatus::Approved->value)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->exists();

        if ($onLeave) {
            throw AttendanceException::make('form', 'Hari ini Anda tercatat cuti yang sudah disetujui, jadi tidak perlu absen.');
        }

        return [$placement->organization_id, $today, CarbonImmutable::now(BusinessClock::timezone())->format('H:i')];
    }

    private function row(string $employeeId, string $date): ?stdClass
    {
        $row = DB::table('hr_attendances')->where('employee_id', $employeeId)->where('date', $date)->lockForUpdate()->first();

        return $row instanceof stdClass ? $row : null;
    }
}
