<?php

namespace Modules\HR\Application\Leave;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\HR\Contracts\ApprovedLeaves;
use Modules\HR\Domain\Leave\LeaveStatus;

/**
 * Implementasi kontrak ApprovedLeaves dari `hr_leave_requests` (status DISETUJUI).
 */
final class EloquentApprovedLeaves implements ApprovedLeaves
{
    public function byDate(string $tenantId, array $employeeIds, string $start, string $end): array
    {
        if ($employeeIds === [] || $end < $start) {
            return [];
        }

        $rows = DB::table('hr_leave_requests as r')
            ->join('hr_leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->where('r.tenant_id', $tenantId)
            ->whereIn('r.employee_id', $employeeIds)
            ->where('r.status', LeaveStatus::Approved->value)
            ->where('r.start_date', '<=', $end)
            ->where('r.end_date', '>=', $start)
            ->get(['r.employee_id', 'r.start_date', 'r.end_date', 't.name']);

        $result = [];

        foreach ($rows as $row) {
            $day = CarbonImmutable::parse(max(substr((string) $row->start_date, 0, 10), $start));
            $last = min(substr((string) $row->end_date, 0, 10), $end);

            while ($day->toDateString() <= $last) {
                $result[(string) $row->employee_id][$day->toDateString()] = (string) $row->name;
                $day = $day->addDay();
            }
        }

        return $result;
    }
}
