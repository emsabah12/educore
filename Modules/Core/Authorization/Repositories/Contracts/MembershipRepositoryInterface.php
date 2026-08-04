<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories\Contracts;

use Illuminate\Support\Collection;
use Modules\Core\Authorization\Models\Membership;

interface MembershipRepositoryInterface
{
    public function findById(
        string $id,
    ): ?Membership;

    /**
     * Mengambil membership aktif berdasarkan user dan tenant.
     */
    public function findActiveMembership(
        string $userId,
        string $tenantId,
    ): ?Membership;

    /**
     * Mengambil target membership aktif dalam tenant tertentu.
     *
     * Digunakan untuk use case tenant-scoped seperti assignment role.
     */
    public function findActiveMembershipByIdAndTenant(
        string $membershipId,
        string $tenantId,
    ): ?Membership;

    /**
     * Mengambil membership aktif yang dimiliki user tertentu.
     *
     * Membership hanya dikembalikan jika:
     * - membership ID sesuai;
     * - dimiliki authenticated user;
     * - membership berstatus ACTIVE;
     * - tenant membership masih aktif.
     *
     * Digunakan untuk use case pemilihan atau perpindahan
     * active membership context.
     */
    public function findActiveMembershipByIdForUser(
        string $membershipId,
        string $userId,
    ): ?Membership;

    /**
     * @return Collection<int, Membership>
     */
    public function findByUser(
        string $userId,
    ): Collection;

    /**
     * @return Collection<int, Membership>
     */
    public function findByTenant(
        string $tenantId,
    ): Collection;

    /**
     * @return Collection<int, Membership>
     */
    public function all(): Collection;

    public function exists(
        string $id,
    ): bool;

    public function save(
        Membership $membership,
    ): Membership;

    public function delete(
        Membership $membership,
    ): void;
}
