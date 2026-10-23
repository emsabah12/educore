<?php

namespace Modules\HR\Application\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Domain\Attendance\AttendanceStatus;
use Modules\HR\Domain\Leave\LeaveStatus;

/**
 * Data halaman Presensi saya (PRD-001 §14.2, HR-4a): jam wajib & presensi hari ini, riwayat bulan
 * berjalan s.d. hari ini. Hak akses (pegawai itu sendiri) dicek pemanggil.
 *
 * @phpstan-type Day array{date: string, required: bool, expected: string, status: string|null, status_label: string, check_in: string|null, check_out: string|null, check_in_self: bool, check_out_self: bool, late: int, early: int, note: string|null}
 */
final class MyAttendance
{
    public const STATE_LEAVE = 'CUTI';

    public const STATE_EMPTY = 'BELUM';

    public function __construct(
        private readonly ExpectedHours $expected,
    ) {}

    /**
     * @return array{organization: string|null, today: Day|null, days: list<Day>, canCheckIn: bool, canCheckOut: bool}
     */
    public function build(string $tenantId, string $employeeId): array
    {
        $organizationId = DB::table('hr_placements')
            ->where('employee_id', $employeeId)
            ->where('is_primary', true)
            ->whereNull('end_date')
            ->value('organization_id');

        if (! is_string($organizationId)) {
            return ['organization' => null, 'today' => null, 'days' => [], 'canCheckIn' => false, 'canCheckOut' => false];
        }

        $today = BusinessClock::todayString();
        $first = BusinessClock::today()->startOfMonth()->toDateString();
        $expected = $this->expected->forEmployee($tenantId, $employeeId, $organizationId, $first, $today);

        $rows = DB::table('hr_attendances')
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$first, $today])
            ->get()
            ->keyBy(fn ($row): string => substr((string) $row->date, 0, 10));

        $leaves = DB::table('hr_leave_requests')
            ->where('employee_id', $employeeId)
            ->where('status', LeaveStatus::Approved->value)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $first)
            ->get(['start_date', 'end_date']);

        $days = [];
        $day = CarbonImmutable::parse($first);

        while ($day->toDateString() <= $today) {
            $date = $day->toDateString();
            $plan = $expected[$date] ?? ['required' => false, 'label' => 'Libur'];
            $row = $rows->get($date);
            $onLeave = $leaves->contains(fn ($leave): bool => substr((string) $leave->start_date, 0, 10) <= $date && substr((string) $leave->end_date, 0, 10) >= $date);

            $status = $row === null ? null : AttendanceStatus::from((string) $row->status);

            $days[] = [
                'date' => $date,
                'required' => $plan['required'],
                'expected' => $plan['label'],
                'status' => match (true) {
                    $onLeave => self::STATE_LEAVE,
                    $status !== null => $status->value,
                    $plan['required'] => self::STATE_EMPTY,
                    default => null,
                },
                'status_label' => match (true) {
                    $onLeave => 'Cuti',
                    $status !== null => $status->label(),
                    $plan['required'] => 'Belum diisi',
                    default => '—',
                },
                'check_in' => $row === null || $row->check_in === null ? null : ManageAttendance::display((string) $row->check_in),
                'check_out' => $row === null || $row->check_out === null ? null : ManageAttendance::display((string) $row->check_out),
                'check_in_self' => $row !== null && $row->check_in_source === ManageAttendance::SOURCE_SELF,
                'check_out_self' => $row !== null && $row->check_out_source === ManageAttendance::SOURCE_SELF,
                'late' => $row === null ? 0 : (int) $row->late_minutes,
                'early' => $row === null ? 0 : (int) $row->early_minutes,
                'note' => $row === null || $row->note === null ? null : (string) $row->note,
            ];

            $day = $day->addDay();
        }

        $todayRow = $days === [] ? null : $days[count($days) - 1];
        $todayRecord = $rows->get($today);
        $todayStatus = $todayRow['status'] ?? null;

        return [
            'organization' => (string) DB::table('organizations')->where('id', $organizationId)->value('name'),
            'today' => $todayRow,
            // Urut terbaru dulu untuk riwayat.
            'days' => array_reverse($days),
            'canCheckIn' => $todayStatus !== self::STATE_LEAVE && ($todayRecord === null || ($todayRecord->check_in === null && $todayRecord->status === AttendanceStatus::Present->value)),
            'canCheckOut' => $todayRecord !== null && $todayRecord->check_in !== null && $todayRecord->check_out === null,
        ];
    }
}
