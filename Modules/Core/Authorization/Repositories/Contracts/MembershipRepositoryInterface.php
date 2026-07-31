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

    public function findActiveMembership(
        string $userId,
        string $tenantId,
    ): ?Membership;

    public function findActiveMembershipForUser(
        string $membershipId,
        string $userId,
    ): ?Membership;

    /**
     * @return Collection<int, Membership>
     */
    public function findActiveMembershipsForUser(
        string $userId,
    ): Collection;

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
