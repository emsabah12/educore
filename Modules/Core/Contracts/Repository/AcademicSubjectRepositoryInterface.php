<?php

declare(strict_types=1);

namespace Modules\Core\Contracts\Repository;

interface AcademicSubjectRepositoryInterface
{
    public function getByTenantPaginated(string $tenantId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    public function findByIdForTenant(string $id, string $tenantId): array;

    public function createForTenant(string $tenantId, array $data): array;
}
