<?php

namespace Modules\HR\Application\Leave;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Employee\EmployeeStatus;
use Modules\HR\Domain\Leave\Exceptions\LeaveException;
use Modules\HR\Domain\Leave\LeaveType;

/**
 * Penyesuaian jatah cuti oleh Admin SDM (PRD-001 §13.2 & §13.5, HR-3d-b).
 *
 * Aturan [ASUMSI]:
 * - Hanya jenis berjatah, pegawai aktif, tahun berjalan atau tahun sebelumnya.
 * - Jumlah hari bukan 0, antara −366 dan 366; alasan wajib (maks. 300), tidak masuk audit.
 * - Pengurangan tidak boleh membuat sisa (jatah + penyesuaian − terpakai) negatif.
 * - Admin tidak menyesuaikan jatahnya sendiri (BR-017).
 *
 * Hak akses (`hr.leave.manage` tingkat Yayasan) dicek pemanggil.
 */
final class ManageLeaveAdjustments
{
    public const REASON_MAX_LENGTH = 300;

    public const MAX_DAYS = 366;

    public function __construct(
        private readonly LeaveBalances $balances,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{type: string, remaining: int} nama jenis & sisa sesudah penyesuaian
     *
     * @throws LeaveException
     */
    public function adjust(string $tenantId, string $employeeId, string $typeId, int $year, int $days, string $reason, string $membershipId): array
    {
        $reason = trim($reason);
        $thisYear = (int) BusinessClock::today()->format('Y');

        if ($days === 0 || abs($days) > self::MAX_DAYS) {
            throw LeaveException::make('days', 'Isi jumlah hari selain 0, paling banyak '.self::MAX_DAYS.' (pakai tanda minus untuk mengurangi).');
        }

        if ($year !== $thisYear && $year !== $thisYear - 1) {
            throw LeaveException::make('year', 'Penyesuaian hanya untuk tahun '.($thisYear - 1)." atau {$thisYear}.");
        }

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw LeaveException::make('reason', 'Tulis alasan penyesuaian, maksimal '.self::REASON_MAX_LENGTH.' karakter.');
        }

        return DB::transaction(function () use ($tenantId, $employeeId, $typeId, $year, $days, $reason, $membershipId): array {
            // Antrean per pegawai, sama dengan pengajuan & persetujuan: saldo tidak balapan.
            $employee = Str::isUuid($employeeId)
                ? Employee::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($employeeId)->lockForUpdate()->first()
                : null;

            if (! $employee instanceof Employee || $employee->status !== EmployeeStatus::Active) {
                throw LeaveException::make('form', 'Jatah hanya bisa disesuaikan untuk pegawai aktif.');
            }

            if ($employee->membership_id === $membershipId) {
                throw LeaveException::make('form', 'Anda tidak bisa menyesuaikan jatah cuti Anda sendiri. Minta Admin SDM lain.');
            }

            $type = Str::isUuid($typeId)
                ? LeaveType::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($typeId)->first()
                : null;

            if (! $type instanceof LeaveType || ! $type->has_quota) {
                throw LeaveException::make('leave_type_id', 'Pilih jenis cuti yang berjatah.');
            }

            $balance = $this->balances->of($type, $employee->id, $year);

            if ($balance['remaining'] + $days < 0) {
                throw LeaveException::make('days', "Sisa jatah {$type->name} tahun {$year} tinggal {$balance['remaining']} hari; pengurangan paling banyak {$balance['remaining']} hari.");
            }

            DB::table('hr_leave_adjustments')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'year' => $year,
                'days' => $days,
                'reason' => $reason,
                'created_by_membership_id' => $membershipId,
                'created_at' => CarbonImmutable::now(),
            ]);

            $this->audit->record(
                HrAuditAction::LEAVE_ADJUSTED,
                HrAuditAction::SUBJECT_EMPLOYEE,
                $employee->id,
                ['jenis' => $type->name, 'tahun' => $year, 'hari' => $days],
                tenantId: $tenantId,
            );

            return ['type' => $type->name, 'remaining' => $balance['remaining'] + $days];
        });
    }
}
