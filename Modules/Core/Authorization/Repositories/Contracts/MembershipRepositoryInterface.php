<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories\Contracts;

use Modules\Core\Authorization\Models\Membership;

interface MembershipRepositoryInterface
{
    /**
     * Mencari membership aktif berdasarkan user dan tenant eksplisit.
     *
     * Digunakan pada authentication flow sebelum current TenantContext
     * tersedia.
     */
    public function findActiveMembership(
        string $userId,
        string $tenantId,
    ): ?Membership;

    /**
     * Mencari target membership aktif dalam tenant tertentu.
     *
     * Digunakan pada operasi tenant-scoped seperti assignment role.
     */
    public function findActiveMembershipByIdAndTenant(
        string $membershipId,
        string $tenantId,
    ): ?Membership;

    /**
     * Mencari membership aktif berdasarkan membership ID dan pemiliknya.
     *
     * Digunakan untuk switch membership sebelum tenant tujuan dijadikan
     * current TenantContext.
     */
    public function findActiveMembershipByIdForUser(
        string $membershipId,
        string $userId,
    ): ?Membership;
}
