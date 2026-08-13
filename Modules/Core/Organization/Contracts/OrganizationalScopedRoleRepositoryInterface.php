<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Contracts;

use Illuminate\Support\Collection;
use Modules\Core\Authorization\Models\Role;

interface OrganizationalScopedRoleRepositoryInterface
{
    /**
     * Resolve active scoped roles that cover the supplied verified context.
     *
     * @return Collection<int, Role>
     */
    public function rolesForContext(
        string $tenantId,
        string $membershipId,
        string $organizationId,
        ?string $organizationUnitId,
    ): Collection;
}
