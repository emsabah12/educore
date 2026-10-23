<?php

namespace Modules\HR\Application\Attendance;

use Modules\HR\Contracts\TeachingSchedule;

/**
 * Implementasi bawaan bila modul Academic tidak terpasang: tidak ada jadwal mengajar.
 */
final class NoTeachingSchedule implements TeachingSchedule
{
    public function windows(string $tenantId, array $employeeIds, string $start, string $end): array
    {
        return [];
    }
}
