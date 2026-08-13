<?php

declare(strict_types=1);

namespace Modules\Core\Organization\Contracts;

interface OrganizationalAuthorizationServiceInterface
{
    public function hasRole(
        string $roleName,
    ): bool;

    public function hasPermission(
        string $permissionName,
    ): bool;
}
