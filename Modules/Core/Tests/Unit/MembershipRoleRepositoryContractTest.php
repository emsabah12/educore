<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit;

use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class MembershipRoleRepositoryContractTest extends TestCase
{
    public function test_contract_exposes_only_tenant_bound_operations(): void
    {
        $reflection = new ReflectionClass(
            MembershipRoleRepositoryInterface::class,
        );

        $methods = [];

        foreach (
            $reflection->getMethods(
                ReflectionMethod::IS_PUBLIC,
            ) as $method
        ) {
            $methods[$method->getName()] = array_map(
                static fn(
                    \ReflectionParameter $parameter,
                ): string => $parameter->getName(),
                $method->getParameters(),
            );
        }

        ksort($methods);

        $this->assertSame(
            [
                'assignRole' => [
                    'membershipId',
                    'tenantId',
                    'roleId',
                ],
                'membershipHasRole' => [
                    'membershipId',
                    'tenantId',
                    'roleName',
                ],
                'rolesForMembership' => [
                    'membershipId',
                    'tenantId',
                ],
            ],
            $methods,
            'Membership role repository must not expose unbounded pivot operations.',
        );
    }
}
