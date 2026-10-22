<?php

namespace Modules\HR\Application\Leave;

use Illuminate\Support\Facades\DB;
use Modules\HR\Domain\Leave\LeaveStatus;
use Modules\HR\Domain\Leave\LeaveType;

/**
 * Saldo jatah cuti (PRD-001 §13.2, OD-HR-08): per pegawai × jenis berjatah × tahun kalender.
 *
 * saldo = jatah per tahun + penyesuaian (HR-3d) − hari cuti DISETUJUI yang jatuh di tahun itu.
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
        $adjustment = 0;
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
