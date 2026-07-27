<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Contracts;

interface AuthorizationServiceInterface
{
    /**
     * Menentukan apakah user memiliki role tertentu
     * pada membership yang valid dan aktif.
     *
     * Authorization bersifat contextual:
     *
     * User
     *   -> Membership
     *      -> Tenant
     *         -> Role
     *
     * @param string $userId
     * @param string $membershipId
     * @param string $roleName
     * @param string|null $tenantId
     */
    public function hasRoleInMembership(
        string $userId,
        string $membershipId,
        string $roleName,
        ?string $tenantId = null
    ): bool;

    /**
     * Menentukan apakah user memiliki permission tertentu
     * pada membership yang valid dan aktif.
     *
     * Permission diperoleh melalui:
     *
     * membership_roles
     *   -> roles
     *   -> role_permissions
     *   -> permissions
     *
     * @param string $userId
     * @param string $membershipId
     * @param string $permissionName
     * @param string|null $tenantId
     */
    public function hasPermissionInMembership(
        string $userId,
        string $membershipId,
        string $permissionName,
        ?string $tenantId = null
    ): bool;
}
