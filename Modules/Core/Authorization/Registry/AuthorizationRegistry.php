<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Registry;

use Modules\Core\Authorization\Contracts\AuthorizationRegistryInterface;
use Modules\Core\Authorization\DTO\CanonicalGrant;
use Modules\Core\Authorization\DTO\CanonicalPermission;
use Modules\Core\Authorization\DTO\CanonicalRole;

final readonly class AuthorizationRegistry implements AuthorizationRegistryInterface
{
    /**
     * @var array<string, CanonicalRole>
     */
    private array $roles;

    /**
     * @var array<string, CanonicalPermission>
     */
    private array $permissions;

    /**
     * role => permission names
     *
     * @var array<string, list<string>>
     */
    private array $grantsMap;

    /**
     * @var list<CanonicalGrant>
     */
    private array $grantObjects;

    /**
     * @param iterable<CanonicalRole> $roles
     * @param iterable<CanonicalPermission> $permissions
     * @param iterable<CanonicalGrant> $grants
     */
    public function __construct(
        iterable $roles,
        iterable $permissions,
        iterable $grants,
    ) {
        $roleMap = [];

        foreach ($roles as $role) {
            $roleMap[$role->name] = $role;
        }

        $permissionMap = [];

        foreach ($permissions as $permission) {
            $permissionMap[$permission->name] = $permission;
        }

        $grantMap = [];

        $grantObjects = [];

        foreach ($grants as $grant) {

            $grantObjects[] = $grant;

            $grantMap[$grant->role] ??= [];

            $grantMap[$grant->role][] = $grant->permission;
        }

        $this->roles = $roleMap;
        $this->permissions = $permissionMap;
        $this->grantsMap = $grantMap;
        $this->grantObjects = $grantObjects;
    }

    /**
     * @return list<CanonicalRole>
     */
    public function roles(): array
    {
        return array_values($this->roles);
    }

    /**
     * @return list<CanonicalPermission>
     */
    public function permissions(): array
    {
        return array_values($this->permissions);
    }

    /**
     * @return list<CanonicalGrant>
     */
    public function grants(): array
    {
        return $this->grantObjects;
    }

    public function role(
        string $name,
    ): ?CanonicalRole {
        return $this->roles[$name] ?? null;
    }

    public function permission(
        string $name,
    ): ?CanonicalPermission {
        return $this->permissions[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function permissionsForRole(
        string $role,
    ): array {
        return $this->grantsMap[$role] ?? [];
    }

    public function hasRole(
        string $role,
    ): bool {
        return isset($this->roles[$role]);
    }

    public function hasPermission(
        string $permission,
    ): bool {
        return isset($this->permissions[$permission]);
    }
}
