<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit;

use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class MembershipRepositoryContractTest extends TestCase
{
    public function test_contract_exposes_only_explicit_membership_boundaries(): void
    {
        $reflection = new ReflectionClass(
            MembershipRepositoryInterface::class,
        );

        $methodNames = array_map(
            static fn(ReflectionMethod $method): string =>
            $method->getName(),
            $reflection->getMethods(
                ReflectionMethod::IS_PUBLIC,
            ),
        );

        sort($methodNames);

        $expectedMethods = [
            'findActiveMembership',
            'findActiveMembershipByIdAndTenant',
            'findActiveMembershipByIdForUser',
        ];

        sort($expectedMethods);

        $this->assertSame(
            $expectedMethods,
            $methodNames,
            'Membership repository must not expose unbounded lookup, listing, or mutation methods.',
        );
    }
}
