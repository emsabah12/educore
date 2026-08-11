<?php

declare(strict_types=1);

namespace Modules\Academic\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface StudentRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, object>
     */
    public function getByTenantPaginated(
        string $tenantId,
        int $perPage = 15,
    ): LengthAwarePaginator;

    /**
     * @return array<string, mixed>
     */
    public function findByIdForTenant(
        string $id,
        string $tenantId,
    ): array;

    /**
     * Persist only the Student profile. Person and Membership provisioning
     * belong to the application orchestration service.
     *
     * @param array{
     *     class_id?: string|null,
     *     nis?: string|null,
     *     nisn?: string|null,
     *     status?: string
     * } $data
     *
     * @return array<string, mixed>
     */
    public function createProfileForTenant(
        string $tenantId,
        string $membershipId,
        array $data,
    ): array;
}
