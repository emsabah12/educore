<?php

namespace Modules\Academic\Application\Guardian;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Contracts\GuardianChildren;

/**
 * Isi kontrak GuardianChildren (PRD-003 FN-5): tautan orang tua/wali dari MyChildren, pendaftaran dari ac_enrollments.
 */
final class AcademicGuardianChildren implements GuardianChildren
{
    public function __construct(
        private readonly MyChildren $children,
    ) {}

    public function child(string $tenantId, string $guardianMembershipId, string $studentId): ?array
    {
        $name = $this->children->name($tenantId, $guardianMembershipId, $studentId);

        if ($name === null) {
            return null;
        }

        $rows = DB::table('ac_enrollments as e')
            ->join('organizations as o', 'o.id', '=', 'e.organization_id')
            ->where('e.tenant_id', $tenantId)
            ->where('e.student_id', $studentId)
            ->orderByRaw('e.end_date IS NOT NULL')
            ->orderBy('o.name')
            ->orderByDesc('e.start_date')
            ->orderBy('e.id')
            ->get(['e.id', 'e.organization_id', 'o.name', 'e.student_number', 'e.end_date']);

        $enrollments = [];

        foreach ($rows as $row) {
            $enrollments[] = [
                'enrollment_id' => (string) $row->id,
                'organization_id' => (string) $row->organization_id,
                'organization' => (string) $row->name,
                'student_number' => $row->student_number === null ? null : (string) $row->student_number,
                'active' => $row->end_date === null,
            ];
        }

        return ['id' => $studentId, 'name' => $name, 'enrollments' => $enrollments];
    }
}
