<?php

declare(strict_types=1);

namespace Modules\Academic\Contracts\Repository;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AcademicClassRepositoryInterface
{
    public function getByTenantPaginated(string $tenantId, int $perPage = 15): LengthAwarePaginator;

    public function findByIdForTenant(string $id, string $tenantId): array;

    public function createForTenant(string $tenantId, array $data): array;
}
