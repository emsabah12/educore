<?php

declare(strict_types=1);

namespace Modules\Dormitory\Tests\Unit\Infrastructure\Eligibility;

use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;
use Modules\Dormitory\Domain\Exceptions\ResidentCheckInException;
use Modules\Dormitory\Infrastructure\Eligibility\MembershipResidentEligibilityChecker;
use PHPUnit\Framework\TestCase;

final class MembershipResidentEligibilityCheckerTest extends TestCase
{
    public function test_eligibility_uses_shared_lock_membership_lookup(): void
    {
        $membership = new Membership;

        $repository = new class($membership) implements MembershipRepositoryInterface
        {
            public bool $normalLookupCalled = false;

            public bool $sharedLookupCalled = false;

            public function __construct(
                private readonly Membership $membership,
            ) {}

            public function findActiveMembershipByIdAndTenant(
                string $membershipId,
                string $tenantId,
            ): ?Membership {
                $this->normalLookupCalled = true;

                return $this->membership;
            }

            public function findActiveMembershipByIdAndTenantForShare(
                string $membershipId,
                string $tenantId,
            ): ?Membership {
                $this->sharedLookupCalled = true;

                return $this->membership;
            }

            public function findActiveMembershipByIdForPerson(
                string $membershipId,
                string $personId,
            ): ?Membership {
                return $this->membership;
            }
        };

        $checker = new MembershipResidentEligibilityChecker(
            $repository,
        );

        $checker->assertEligible(
            'tenant-id',
            'membership-id',
            'REGULAR_RESIDENT',
        );

        $this->assertTrue($repository->sharedLookupCalled);
        $this->assertFalse($repository->normalLookupCalled);
    }

    public function test_eligibility_rejects_missing_shared_lock_membership(): void
    {
        $repository = new class implements MembershipRepositoryInterface
        {
            public function findActiveMembershipByIdAndTenant(
                string $membershipId,
                string $tenantId,
            ): ?Membership {
                return new Membership;
            }

            public function findActiveMembershipByIdAndTenantForShare(
                string $membershipId,
                string $tenantId,
            ): ?Membership {
                return null;
            }

            public function findActiveMembershipByIdForPerson(
                string $membershipId,
                string $personId,
            ): ?Membership {
                return null;
            }
        };

        $checker = new MembershipResidentEligibilityChecker(
            $repository,
        );

        $this->expectException(ResidentCheckInException::class);
        $this->expectExceptionMessage(
            'Resident membership is not eligible in the current tenant.',
        );

        $checker->assertEligible(
            'tenant-id',
            'membership-id',
            'REGULAR_RESIDENT',
        );
    }
}
