<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Contracts;

interface AuthorizationServiceInterface
{
    /**
     * Menentukan apakah authenticated user memiliki role
     * pada authorization context saat ini.
     *
     * Authorization context ditentukan secara internal melalui:
     *
     * Authenticated User
     *      ↓
     * AuthorizationContextResolver
     *      ↓
     * Active Membership
     *      ↓
     * membership_roles
     *      ↓
     * roles
     *
     * Caller tidak boleh memberikan userId, membershipId,
     * atau tenantId secara langsung.
     */
    public function hasRole(
        string $roleName,
    ): bool;

    /**
     * Menentukan apakah authenticated user memiliki permission
     * pada authorization context saat ini.
     *
     * Permission diperoleh melalui:
     *
     * membership_roles
     *      ↓
     * roles
     *      ↓
     * role_permissions
     *      ↓
     * permissions
     *
     * Caller tidak boleh memberikan userId, membershipId,
     * atau tenantId secara langsung.
     */
    public function hasPermission(
        string $permissionName,

    ): bool;
}
