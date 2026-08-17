<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Context\AuthorizationContext;
use Modules\Core\Authorization\Contracts\AuthorizationContextResolverInterface;
use Modules\Core\Authorization\Contracts\AuthorizationServiceInterface;
use Modules\Core\Authorization\DTO\TenantCapabilityProjection;
use Modules\Core\Authorization\Exceptions\CapabilityProjectionContextException;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Queries\PermissionCatalogQuery;
use Modules\Core\Authorization\Queries\TenantCapabilityProjectionQuery;
use Modules\Core\Identity\Contracts\ActiveUserResolverInterface;
use Modules\Core\Identity\Models\User;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class TenantCapabilityProjectionQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_projects_only_effective_tenant_permissions(): void
    {
        Permission::query()->create([
            'name' => 'zeta.operation.execute',
            'display_name' => 'Execute Zeta Operation',
            'description' => null,
            'module' => 'Zeta',
        ]);

        Permission::query()->create([
            'name' => 'academic.grades.write',
            'display_name' => 'Write Academic Grades',
            'description' => null,
            'module' => 'Academic',
        ]);

        Permission::query()->create([
            'name' => 'core.notifications.dispatch',
            'display_name' => 'Dispatch Notifications',
            'description' => null,
            'module' => 'Core',
        ]);

        $userId = UuidV7::generate();
        $tenantId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        $context = new AuthorizationContext(
            userId: $userId,
            tenantId: $tenantId,
            membershipId: $membershipId,
        );

        $contextResolver = $this->createMock(
            AuthorizationContextResolverInterface::class,
        );

        $contextResolver
            ->expects($this->once())
            ->method('resolve')
            ->willReturn($context);

        $user = new User();

        $user->forceFill([
            'id' => $userId,
            'status' => 'ACTIVE',
            'is_superadmin' => false,
        ]);

        $activeUserResolver = $this->createMock(
            ActiveUserResolverInterface::class,
        );

        $activeUserResolver
            ->expects($this->once())
            ->method('findActiveById')
            ->with($userId)
            ->willReturn($user);

        $authorizationService = $this->createMock(
            AuthorizationServiceInterface::class,
        );

        /*
         * PermissionCatalogQuery mengurutkan permission berdasarkan
         * canonical name sehingga evaluation order deterministic.
         */
        $authorizationService
            ->expects($this->exactly(3))
            ->method('hasPermission')
            ->willReturnCallback(
                static fn(string $permissionName): bool =>
                in_array(
                    $permissionName,
                    [
                        'academic.grades.write',
                        'core.notifications.dispatch',
                    ],
                    true,
                ),
            );

        $query = new TenantCapabilityProjectionQuery(
            permissionCatalogQuery: $this->app->make(
                PermissionCatalogQuery::class,
            ),
            authorizationService: $authorizationService,
            contextResolver: $contextResolver,
            activeUserResolver: $activeUserResolver,
        );

        $projection = $query->execute();

        $this->assertInstanceOf(
            TenantCapabilityProjection::class,
            $projection,
        );

        $this->assertSame(
            [
                'scope' => [
                    'type' => 'tenant',
                    'tenant_id' => $tenantId,
                    'membership_id' => $membershipId,
                ],
                'is_global_superadmin' => false,
                'permissions' => [
                    'academic.grades.write',
                    'core.notifications.dispatch',
                ],
            ],
            $projection->toArray(),
        );
    }

    public function test_global_superadmin_projects_entire_registered_permission_catalog(): void
    {
        Permission::query()->create([
            'name' => 'academic.grades.write',
            'display_name' => 'Write Academic Grades',
            'description' => null,
            'module' => 'Academic',
        ]);

        Permission::query()->create([
            'name' => 'core.notifications.dispatch',
            'display_name' => 'Dispatch Notifications',
            'description' => null,
            'module' => 'Core',
        ]);

        $userId = UuidV7::generate();
        $tenantId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        $context = new AuthorizationContext(
            userId: $userId,
            tenantId: $tenantId,
            membershipId: $membershipId,
        );

        $contextResolver = $this->createMock(
            AuthorizationContextResolverInterface::class,
        );

        $contextResolver
            ->expects($this->once())
            ->method('resolve')
            ->willReturn($context);

        $user = new User();

        $user->forceFill([
            'id' => $userId,
            'status' => 'ACTIVE',
            'is_superadmin' => true,
        ]);

        $activeUserResolver = $this->createMock(
            ActiveUserResolverInterface::class,
        );

        $activeUserResolver
            ->expects($this->once())
            ->method('findActiveById')
            ->with($userId)
            ->willReturn($user);

        /*
         * Superadmin authority berasal dari users.is_superadmin.
         * Tenant RBAC evaluator tidak perlu dipanggil.
         */
        $authorizationService = $this->createMock(
            AuthorizationServiceInterface::class,
        );

        $authorizationService
            ->expects($this->never())
            ->method('hasPermission');

        $query = new TenantCapabilityProjectionQuery(
            permissionCatalogQuery: $this->app->make(
                PermissionCatalogQuery::class,
            ),
            authorizationService: $authorizationService,
            contextResolver: $contextResolver,
            activeUserResolver: $activeUserResolver,
        );

        $projection = $query->execute();

        $this->assertSame(
            [
                'scope' => [
                    'type' => 'tenant',
                    'tenant_id' => $tenantId,
                    'membership_id' => $membershipId,
                ],
                'is_global_superadmin' => true,
                'permissions' => [
                    'academic.grades.write',
                    'core.notifications.dispatch',
                ],
            ],
            $projection->toArray(),
        );
    }

    public function test_it_fails_closed_when_canonical_active_user_cannot_be_resolved(): void
    {
        $userId = UuidV7::generate();

        $context = new AuthorizationContext(
            userId: $userId,
            tenantId: UuidV7::generate(),
            membershipId: UuidV7::generate(),
        );

        $contextResolver = $this->createMock(
            AuthorizationContextResolverInterface::class,
        );

        $contextResolver
            ->expects($this->once())
            ->method('resolve')
            ->willReturn($context);

        $activeUserResolver = $this->createMock(
            ActiveUserResolverInterface::class,
        );

        $activeUserResolver
            ->expects($this->once())
            ->method('findActiveById')
            ->with($userId)
            ->willReturn(null);

        $authorizationService = $this->createMock(
            AuthorizationServiceInterface::class,
        );

        $authorizationService
            ->expects($this->never())
            ->method('hasPermission');

        $query = new TenantCapabilityProjectionQuery(
            permissionCatalogQuery: $this->app->make(
                PermissionCatalogQuery::class,
            ),
            authorizationService: $authorizationService,
            contextResolver: $contextResolver,
            activeUserResolver: $activeUserResolver,
        );

        $this->expectException(
            CapabilityProjectionContextException::class,
        );

        $query->execute();
    }
}
