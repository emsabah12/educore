<?php

declare(strict_types=1);

namespace Modules\User\Application\Queries;

use Illuminate\Support\Collection;
use Modules\User\Application\DTO\MembershipSummary;

interface UserMembershipQueryInterface
{
    /**
     * Return active tenant memberships owned by the canonical Person linked
     * to the authenticated User account.
     *
     * @return Collection<int, MembershipSummary>
     */
    public function findActiveForUser(
        string $userId,
    ): Collection;
}
