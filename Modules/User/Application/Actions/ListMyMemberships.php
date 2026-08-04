<?php

declare(strict_types=1);

namespace Modules\User\Application\Actions;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\User\Application\DTO\MembershipSummary;
use Modules\User\Application\Queries\UserMembershipQueryInterface;

final readonly class ListMyMemberships
{
    public function __construct(
        private UserMembershipQueryInterface $membershipQuery,
    ) {}

    /**
     * @return Collection<int, MembershipSummary>
     */
    public function execute(
        string $authenticatedUserId,
    ): Collection {
        $authenticatedUserId = trim(
            $authenticatedUserId,
        );

        if ($authenticatedUserId === '') {
            throw new InvalidArgumentException(
                'Authenticated user identifier is required.',
            );
        }

        return $this->membershipQuery->findActiveForUser(
            $authenticatedUserId,
        );
    }
}
