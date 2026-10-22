<?php

namespace Modules\HR\Application\Leave;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Domain\Leave\LeaveRequest;

/**
 * Ringkasan cuti tahun berjalan di detail pegawai (PRD-001 §13.5, HR-3d-b): saldo jenis berjatah dan
 * 5 cuti terbaru. Hanya untuk yang boleh melihat data pribadi pegawai itu (dicek pemanggil).
 *
 * @phpstan-type Summary array{year: int, balances: list<array{type_id: string, name: string, quota: int, adjustment: int, used: int, pending: int, remaining: int}>, recent: list<array{id: string, type: string, start_date: string, end_date: string, days: int, status: string, status_label: string}>}
 */
final class EmployeeLeaveSummary
{
    public const RECENT_LIMIT = 5;

    public function __construct(
        private readonly LeaveBalances $balances,
    ) {}

    /**
     * @return Summary
     */
    public function for(string $tenantId, string $employeeId): array
    {
        $year = (int) BusinessClock::today()->format('Y');
        $names = DB::table('hr_leave_types')->where('tenant_id', $tenantId)->pluck('name', 'id')->all();
        $recent = [];

        $requests = LeaveRequest::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->orderByDesc('start_date')
            ->orderByDesc('created_at')
            ->limit(self::RECENT_LIMIT)
            ->get();

        foreach ($requests as $request) {
            $recent[] = [
                'id' => $request->id,
                'type' => (string) ($names[$request->leave_type_id] ?? '—'),
                'start_date' => $request->start_date->toDateString(),
                'end_date' => $request->end_date->toDateString(),
                'days' => $request->days,
                'status' => $request->status->value,
                'status_label' => LeaveRegister::statusLabel($request->status, $request->current_step, $request->approval_steps),
            ];
        }

        return [
            'year' => $year,
            'balances' => $this->balances->forEmployee($tenantId, $employeeId, $year),
            'recent' => $recent,
        ];
    }
}
