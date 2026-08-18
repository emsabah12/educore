<?php

declare(strict_types=1);

namespace Modules\Dormitory\Infrastructure\Eligibility;

use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;
use Modules\Dormitory\Contracts\ResidentEligibilityCheckerInterface;
use Modules\Dormitory\Domain\Exceptions\ResidentCheckInException;

final readonly class MembershipResidentEligibilityChecker implements ResidentEligibilityCheckerInterface
{
    public function __construct(
        private MembershipRepositoryInterface $membershipRepository,
    ) {}

    public function assertEligible(
        string $tenantId,
        string $membershipId,
        string $residentCategory,
    ): void {
        $membership = $this->membershipRepository
            ->findActiveMembershipByIdAndTenantForShare(
                $membershipId,
                $tenantId,
            );

        if ($membership === null) {
            throw ResidentCheckInException::membershipNotEligible();
        }
    }
}
