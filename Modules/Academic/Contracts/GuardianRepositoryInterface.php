<?php

declare(strict_types=1);

namespace Modules\Academic\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface GuardianRepositoryInterface
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
     * Create only the Guardian profile for an existing canonical Membership.
     * Human identity and Membership provisioning are application-service concerns.
     *
     * @return array<string, mixed>
     */
    public function createProfileForTenant(
        string $tenantId,
        string $membershipId,
    ): array;
}
