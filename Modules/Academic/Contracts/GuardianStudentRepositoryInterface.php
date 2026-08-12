<?php

declare(strict_types=1);

namespace Modules\Academic\Contracts;

interface GuardianStudentRepositoryInterface
{
    /**
     * Attach a canonical Student profile to a canonical Guardian profile
     * inside the same tenant.
     *
     * The operation is idempotent. Re-attaching the same pair succeeds
     * without creating a duplicate row or changing the existing relation.
     * Returns true only when a new association row is created.
     */
    public function attachStudentToGuardian(
        string $tenantId,
        string $guardianId,
        string $studentId,
        string $relationshipType,
    ): bool;

    /**
     * Detach one Guardian-Student association inside the tenant boundary.
     */
    public function detachStudentFromGuardian(
        string $tenantId,
        string $guardianId,
        string $studentId,
    ): bool;

    /**
     * Return the canonical Student projection for one Guardian.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStudentsByGuardian(
        string $tenantId,
        string $guardianId,
    ): array;
}
