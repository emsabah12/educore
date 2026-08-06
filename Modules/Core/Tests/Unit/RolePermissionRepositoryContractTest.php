<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit;

use Modules\Core\Authorization\Repositories\Contracts\RolePermissionRepositoryInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Tests\TestCase;

final class RolePermissionRepositoryContractTest extends TestCase
{
    public function test_contract_exposes_only_permission_check_operation(): void
    {
        $reflection = new ReflectionClass(
            RolePermissionRepositoryInterface::class,
        );

        $methods = [];

        foreach (
            $reflection->getMethods(
                ReflectionMethod::IS_PUBLIC,
            ) as $method
        ) {
            $methods[$method->getName()] = array_map(
                static fn(
                    ReflectionParameter $parameter,
                ): string => $parameter->getName(),
                $method->getParameters(),
            );
        }

        $this->assertSame(
            [
                'roleHasPermission' => [
                    'roleId',
                    'permissionName',
                ],
            ],
            $methods,
            'Role permission repository must not expose generic composite-pivot operations.',
        );
    }
}
