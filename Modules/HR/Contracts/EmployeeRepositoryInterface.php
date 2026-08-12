<?php

declare(strict_types=1);

namespace Modules\HR\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface EmployeeRepositoryInterface
{
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
     * Resolve the canonical Employee profile for a tenant Membership.
     *
     * @return array<string, mixed>|null
     */
    public function findByMembershipForTenant(
        string $membershipId,
        string $tenantId,
    ): ?array;

    /**
     * @param array{nip:string|null,jabatan:string} $data
     * @return array<string, mixed>
     */
    public function createProfileForTenant(
        string $tenantId,
        string $membershipId,
        array $data,
    ): array;
}
