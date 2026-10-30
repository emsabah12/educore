<?php

namespace Modules\Academic\Contracts;

/**
 * Kontrak publik Academic untuk modul lain (mis. Finance portal orang tua, PRD-003 FN-5): anak yang tertaut ke
 * orang tua/wali yang login (ac_student_guardians, PRD-002 §21) beserta pendaftarannya. Tanpa data pribadi.
 *
 * @phpstan-type ChildEnrollment array{enrollment_id: string, organization_id: string, organization: string, student_number: string|null, active: bool}
 */
interface GuardianChildren
{
    /**
     * Anak (siswa) orang tua/wali ini beserta SEMUA pendaftarannya (terbuka maupun sudah ditutup), urut:
     * yang masih berjalan dulu, lalu nama lembaga. Null bila siswa ini bukan anaknya (→ 404 di pemanggil).
     *
     * @return array{id: string, name: string, enrollments: list<ChildEnrollment>}|null
     */
    public function child(string $tenantId, string $guardianMembershipId, string $studentId): ?array;
}
