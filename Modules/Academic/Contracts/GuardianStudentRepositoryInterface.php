<?php

declare(strict_types=1);

namespace Modules\Academic\Contracts;

interface GuardianStudentRepositoryInterface
{
    /**
     * Menghubungkan seorang student dengan guardian
     * dalam tenant tertentu.
     *
     * Operasi bersifat idempotent:
     * jika relasi sudah ada, method mengembalikan true
     * tanpa membuat duplicate record.
     */
    public function attachStudentToGuardian(
        string $tenantId,
        string $guardianId,
        string $studentId,
        string $relationshipType = 'AYAH'
    ): bool;

    /**
     * Memutuskan hubungan guardian dengan student
     * hanya dalam tenant yang diberikan.
     */
    public function detachStudentFromGuardian(
        string $tenantId,
        string $guardianId,
        string $studentId
    ): bool;

    /**
     * Mengambil seluruh student yang terhubung
     * dengan guardian tertentu dalam tenant tertentu.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStudentsByGuardian(
        string $tenantId,
        string $guardianId
    ): array;
}
