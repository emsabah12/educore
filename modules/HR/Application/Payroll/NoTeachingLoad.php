<?php

namespace Modules\HR\Application\Payroll;

use Modules\HR\Contracts\TeachingLoad;

/**
 * Implementasi kosong TeachingLoad bila modul Academic tidak terpasang: tidak ada yang mengajar.
 */
final class NoTeachingLoad implements TeachingLoad
{
    public function weekly(string $tenantId, array $employeeIds, string $date): array
    {
        return [];
    }

    public function taught(string $tenantId, array $employeeIds, string $start, string $end): array
    {
        return [];
    }
}
