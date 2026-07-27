<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;

final class AuthorizationService implements AuthorizationServiceInterface
{
    /**
     * Menentukan apakah user memiliki role tertentu
     * pada membership yang valid dan aktif.
     *
     * Security invariant:
     *
     * 1. Membership harus dimiliki oleh user.
     * 2. Membership harus ACTIVE.
     * 3. Jika tenantId diberikan, membership harus berada
     *    pada tenant tersebut.
     * 4. Role harus terhubung ke membership melalui membership_roles.
     */
    public function hasRoleInMembership(
        string $userId,
        string $membershipId,
        string $roleName,
        ?string $tenantId = null
    ): bool {
        $query = DB::table('memberships')
            ->join(
                'membership_roles',
                'memberships.id',
                '=',
                'membership_roles.membership_id'
            )
            ->join(
                'roles',
                'membership_roles.role_id',
                '=',
                'roles.id'
            )
            ->where('memberships.id', $membershipId)
            ->where('memberships.user_id', $userId)
            ->where('memberships.status', 'ACTIVE')
            ->where('roles.name', $roleName);

        if ($tenantId !== null) {
            $query->where('memberships.tenant_id', $tenantId);
        }

        return $query->exists();
    }

    /**
     * Menentukan apakah user memiliki permission tertentu
     * pada membership yang valid dan aktif.
     *
     * Security invariant:
     *
     * 1. Membership harus dimiliki oleh user.
     * 2. Membership harus ACTIVE.
     * 3. Jika tenantId diberikan, membership harus berada
     *    pada tenant tersebut.
     * 4. Permission harus berasal dari role yang diberikan
     *    kepada membership tersebut.
     */
    public function hasPermissionInMembership(
        string $userId,
        string $membershipId,
        string $permissionName,
        ?string $tenantId = null
    ): bool {
        $query = DB::table('memberships')
            ->join(
                'membership_roles',
                'memberships.id',
                '=',
                'membership_roles.membership_id'
            )
            ->join(
                'role_permissions',
                'membership_roles.role_id',
                '=',
                'role_permissions.role_id'
            )
            ->join(
                'permissions',
                'role_permissions.permission_id',
                '=',
                'permissions.id'
            )
            ->where('memberships.id', $membershipId)
            ->where('memberships.user_id', $userId)
            ->where('memberships.status', 'ACTIVE')
            ->where('permissions.name', $permissionName);

        if ($tenantId !== null) {
            $query->where('memberships.tenant_id', $tenantId);
        }

        return $query->exists();
    }
}
