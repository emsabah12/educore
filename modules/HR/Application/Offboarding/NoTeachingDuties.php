<?php

namespace Modules\HR\Application\Offboarding;

use Modules\HR\Contracts\TeachingDuties;

/**
 * Dipakai bila modul Academic tidak terpasang: tidak ada tugas mengajar.
 */
final class NoTeachingDuties implements TeachingDuties
{
    public function forEmployee(string $tenantId, string $employeeId, string $date): array
    {
        return [];
    }
}
