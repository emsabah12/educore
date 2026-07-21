<?php

declare(strict_types=1);

namespace Modules\Academic\Contracts\Repository;

interface AcademicClassRepositoryInterface
{
    public function getByTenantPaginated(string $tenantId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    public function findByIdForTenant(string $id, string $tenantId): array;

    public function createForTenant(string $tenantId, array $data): array;
}
