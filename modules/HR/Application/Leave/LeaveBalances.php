<?php

namespace Modules\HR\Application\Leave;

use Illuminate\Support\Facades\DB;
use Modules\HR\Domain\Leave\LeaveStatus;
use Modules\HR\Domain\Leave\LeaveType;

/**
 * Saldo jatah cuti (PRD-001 §13.2, OD-HR-08): per pegawai × jenis berjatah × tahun kalender.
 *
 * saldo = jatah per tahun + penyesuaian (`hr_leave_adjustments`, HR-3d-b) − hari cuti DISETUJUI yang
 * jatuh di tahun itu.
 * "Menunggu" ditampilkan terpisah dan ikut dikurangkan saat mengajukan pengajuan baru [ASUMSI].
 * Sisa tidak dibawa ke tahun berikutnya.
 *
 * @phpstan-type Balance array{type_id: string, name: string, quota: int, adjustment: int, used: int, pending: int, remaining: int}
 */
final class LeaveBalances
{
    /**
     * Saldo semua jenis berjatah yang aktif.
     *
     * @return list<Balance>
     */
    public function forEmployee(string $tenantId, string $employeeId, int $year): array
    {
        $types = LeaveType::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('has_quota', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $balances = [];

        foreach ($types as $type) {
            $balances[] = $this->of($type, $employeeId, $year);
        }

        return $balances;
    }

    /**
     * @return Balance
     */
    public function of(LeaveType $type, string $employeeId, int $year, ?string $exceptRequestId = null): array
    {
        $quota = (int) $type->annual_quota;
        $adjustment = $this->adjustment($employeeId, $type->id, $year);
        $used = $this->days($employeeId, $type->id, $year, LeaveStatus::Approved, $exceptRequestId);
        $pending = $this->days($employeeId, $type->id, $year, LeaveStatus::Pending, $exceptRequestId);

        return [
            'type_id' => $type->id,
            'name' => $type->name,
            'quota' => $quota,
            'adjustment' => $adjustment,
            'used' => $used,
            'pending' => $pending,
            'remaining' => $quota + $adjustment - $used,
        ];
    }

    /**
     * Saldo banyak pegawai sekaligus (halaman Saldo cuti & rekap): 3 query untuk semua pegawai.
     *
     * @param  list<string>  $employeeIds
     * @param  iterable<LeaveType>  $types  jenis berjatah
     * @return array<string, array<string, Balance>> pegawai → jenis → saldo
     */
    public function table(array $employeeIds, iterable $types, int $year): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $sums = [];

        $requests = DB::table('hr_leave_requests')
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', [LeaveStatus::Approved->value, LeaveStatus::Pending->value])
            ->groupBy('employee_id', 'leave_type_id', 'status')
            ->selectRaw('employee_id, leave_type_id, status, coalesce(sum((days_by_year->>?)::int), 0) AS total', [(string) $year])
            ->get();

        foreach ($requests as $row) {
            $sums[(string) $row->employee_id][(string) $row->leave_type_id][(string) $row->status] = (int) $row->total;
        }

        $adjustments = DB::table('hr_leave_adjustments')
            ->whereIn('employee_id', $employeeIds)
            ->where('year', $year)
            ->groupBy('employee_id', 'leave_type_id')
            ->selectRaw('employee_id, leave_type_id, sum(days) AS total')
            ->get();

        foreach ($adjustments as $row) {
            $sums[(string) $row->employee_id][(string) $row->leave_type_id]['ADJ'] = (int) $row->total;
        }

        $table = [];

        foreach ($employeeIds as $employeeId) {
            foreach ($types as $type) {
                $quota = (int) $type->annual_quota;
                $values = $sums[$employeeId][$type->id] ?? [];
                $adjustment = $values['ADJ'] ?? 0;
                $used = $values[LeaveStatus::Approved->value] ?? 0;

                $table[$employeeId][$type->id] = [
                    'type_id' => $type->id,
                    'name' => $type->name,
                    'quota' => $quota,
                    'adjustment' => $adjustment,
                    'used' => $used,
                    'pending' => $values[LeaveStatus::Pending->value] ?? 0,
                    'remaining' => $quota + $adjustment - $used,
                ];
            }
        }

        return $table;
    }

    private function adjustment(string $employeeId, string $typeId, int $year): int
    {
        $row = DB::table('hr_leave_adjustments')
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $typeId)
            ->where('year', $year)
            ->selectRaw('coalesce(sum(days), 0) AS total')
            ->first();

        return $row === null ? 0 : (int) $row->total;
    }

    /**
     * Jumlah hari kerja pengajuan berstatus itu yang jatuh di tahun ini.
     */
    private function days(string $employeeId, string $typeId, int $year, LeaveStatus $status, ?string $exceptRequestId): int
    {
        $query = DB::table('hr_leave_requests')
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $typeId)
            ->where('status', $status->value);

        if ($exceptRequestId !== null) {
            $query->where('id', '!=', $exceptRequestId);
        }

        $row = $query->selectRaw('coalesce(sum((days_by_year->>?)::int), 0) AS total', [(string) $year])->first();

        return $row === null ? 0 : (int) $row->total;
    }
}
