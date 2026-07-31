<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Contracts;

use Modules\Core\Authorization\DTO\CanonicalGrant;
use Modules\Core\Authorization\DTO\CanonicalPermission;
use Modules\Core\Authorization\DTO\CanonicalRole;

interface AuthorizationRegistryInterface
{
    /**
     * @return list<CanonicalRole>
     */
    public function roles(): array;

    /**
     * @return list<CanonicalPermission>
     */
    public function permissions(): array;

    /**
     * @return list<CanonicalGrant>
     */
    public function grants(): array;

    public function role(
        string $name,
    ): ?CanonicalRole;

    public function permission(
        string $name,
    ): ?CanonicalPermission;

    /**
     * @return list<string>
     */
    public function permissionsForRole(
        string $role,
    ): array;

    public function hasRole(
        string $role,
    ): bool;

    public function hasPermission(
        string $permission,
    ): bool;
}
