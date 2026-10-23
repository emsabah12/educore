<?php

namespace Modules\HR\Application\Leave;

use Modules\HR\Contracts\SchoolWeeklyOff;

/**
 * Implementasi bawaan bila modul Academic tidak terpasang: tidak ada kalender akademik.
 */
final class NoSchoolWeeklyOff implements SchoolWeeklyOff
{
    public function forOrganizations(string $tenantId, array $organizationIds): array
    {
        return [];
    }
}
