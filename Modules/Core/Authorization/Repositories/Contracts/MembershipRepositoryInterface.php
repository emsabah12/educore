<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories\Contracts;

use Modules\Core\Authorization\Models\Membership;

interface MembershipRepositoryInterface
{
    /**
     * Mencari membership aktif berdasarkan membership ID
     * dalam tenant tertentu.
     *
     * Digunakan untuk validasi explicit authenticated
     * membership context dan operasi tenant-scoped.
     */
    public function findActiveMembershipByIdAndTenant(
        string $membershipId,
        string $tenantId,
    ): ?Membership;

    /**
     * Mencari membership aktif berdasarkan membership ID
     * dan authenticated owner.
     *
     * Digunakan untuk switch membership sebelum tenant tujuan
     * dijadikan current TenantContext.
     */
    public function findActiveMembershipByIdForUser(
        string $membershipId,
        string $userId,
    ): ?Membership;
}
