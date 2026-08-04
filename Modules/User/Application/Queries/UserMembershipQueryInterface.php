<?php

declare(strict_types=1);

namespace Modules\User\Application\Queries;

use Illuminate\Support\Collection;
use Modules\User\Application\DTO\MembershipSummary;

interface UserMembershipQueryInterface
{
    /**
     * Mengambil seluruh membership aktif milik user.
     *
     * @return Collection<int, MembershipSummary>
     */
    public function findActiveForUser(
        string $userId,
    ): Collection;
}
