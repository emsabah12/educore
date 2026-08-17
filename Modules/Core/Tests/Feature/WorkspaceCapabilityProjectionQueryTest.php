<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Context\AuthorizationContext;
use Modules\Core\Authorization\Contracts\AuthorizationContextResolverInterface;
use Modules\Core\Authorization\Exceptions\CapabilityProjectionContextException;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Queries\PermissionCatalogQuery;
use Modules\Core\Authorization\Queries\WorkspaceCapabilityProjectionQuery;
use Modules\Core\Identity\Contracts\ActiveUserResolverInterface;
use Modules\Core\Identity\Models\User;
use Modules\Core\Organization\Context\OrganizationalContext;
use Modules\Core\Organization\Contracts\OrganizationalAuthorizationServiceInterface;
use Modules\Core\Organization\Contracts\OrganizationalContextInterface;
use Modules\Core\Organization\Contracts\OrganizationalContextResolverInterface;
use Modules\Core\Organization\Exceptions\OrganizationalContextException;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class WorkspaceCapabilityProjectionQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_projects_only_effective_permissions_for_organization_context(): void
    {
        $this->seedPermissions([
            'academic.grades.write',
            'core.notifications.dispatch',
            'dormitory.rooms.view',
        ]);

        $userId = UuidV7::generate();
        $tenantId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        $organizationalContext =
            new OrganizationalContext(
                tenantId: $tenantId,
                membershipId: $membershipId,
                assignmentId: UuidV7::generate(),
                organizationId: UuidV7::generate(),
                organizationUnitId: null,
            );

        $query = $this->makeQuery(
            authorizationContext: new AuthorizationContext(
                userId: $userId,
                tenantId: $tenantId,
                membershipId: $membershipId,
            ),
            currentOrganizationalContext: $organizationalContext,
            resolvedOrganizationalContext: $organizationalContext,
            user: $this->user(
                id: $userId,
                isGlobalSuperadmin: false,
            ),
            effectivePermissions: [
                'academic.grades.write',
                'dormitory.rooms.view',
            ],
        );

        $projection = $query->execute();

        $this->assertSame(
            [
                'scope' => [
                    'type' => 'organization',
                    'tenant_id' => $tenantId,
                    'membership_id' => $membershipId,
                    'organizational_assignment_id' =>
                    $organizationalContext
                        ->assignmentId,
                    'organization_id' =>
                    $organizationalContext
                        ->organizationId,
                    'organization_unit_id' => null,
                ],
                'is_global_superadmin' => false,
                'permissions' => [
                    'academic.grades.write',
                    'dormitory.rooms.view',
                ],
            ],
            $projection->toArray(),
        );
    }

    public function test_it_projects_organization_unit_scope(): void
    {
        $this->seedPermissions([
            'dormitory.rooms.view',
        ]);

        $userId = UuidV7::generate();
        $tenantId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        $organizationalContext =
            new OrganizationalContext(
                tenantId: $tenantId,
                membershipId: $membershipId,
                assignmentId: UuidV7::generate(),
                organizationId: UuidV7::generate(),
                organizationUnitId: UuidV7::generate(),
            );

        $query = $this->makeQuery(
            authorizationContext: new AuthorizationContext(
                userId: $userId,
                tenantId: $tenantId,
                membershipId: $membershipId,
            ),
            currentOrganizationalContext: $organizationalContext,
            resolvedOrganizationalContext: $organizationalContext,
            user: $this->user(
                id: $userId,
                isGlobalSuperadmin: false,
            ),
            effectivePermissions: [
                'dormitory.rooms.view',
            ],
        );

        $projection = $query->execute();

        $this->assertSame(
            'organization_unit',
            $projection->toArray()['scope']['type'],
        );

        $this->assertSame(
            $organizationalContext->organizationUnitId,
            $projection
                ->toArray()['scope']['organization_unit_id'],
        );
    }

    public function test_global_superadmin_does_not_bypass_workspace_authorization_evaluator(): void
    {
        $this->seedPermissions([
            'academic.grades.write',
            'dormitory.rooms.view',
        ]);

        $userId = UuidV7::generate();
        $tenantId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        $organizationalContext =
            new OrganizationalContext(
                tenantId: $tenantId,
                membershipId: $membershipId,
                assignmentId: UuidV7::generate(),
                organizationId: UuidV7::generate(),
                organizationUnitId: null,
            );

        $query = $this->makeQuery(
            authorizationContext: new AuthorizationContext(
                userId: $userId,
                tenantId: $tenantId,
                membershipId: $membershipId,
            ),
            currentOrganizationalContext: $organizationalContext,
            resolvedOrganizationalContext: $organizationalContext,
            user: $this->user(
                id: $userId,
                isGlobalSuperadmin: true,
            ),
            effectivePermissions: [
                'academic.grades.write',
            ],
        );

        $projection = $query->execute();

        $this->assertTrue(
            $projection->isGlobalSuperadmin,
        );

        $this->assertSame(
            [
                'academic.grades.write',
            ],
            $projection->permissions,
        );
    }

    public function test_it_fails_closed_when_organizational_context_is_missing(): void
    {
        $authorizationContext =
            new AuthorizationContext(
                userId: UuidV7::generate(),
                tenantId: UuidV7::generate(),
                membershipId: UuidV7::generate(),
            );

        $authorizationContextResolver =
            $this->createMock(
                AuthorizationContextResolverInterface::class,
            );

        $authorizationContextResolver
            ->expects($this->once())
            ->method('resolve')
            ->willReturn(
                $authorizationContext,
            );

        $organizationalContext =
            $this->createMock(
                OrganizationalContextInterface::class,
            );

        $organizationalContext
            ->expects($this->once())
            ->method('getCurrentContext')
            ->willReturn(null);

        $organizationalContextResolver =
            $this->createMock(
                OrganizationalContextResolverInterface::class,
            );

        $organizationalContextResolver
            ->expects($this->never())
            ->method('resolve');

        $organizationalAuthorizationService =
            $this->createMock(
                OrganizationalAuthorizationServiceInterface::class,
            );

        $organizationalAuthorizationService
            ->expects($this->never())
            ->method('hasPermission');

        $activeUserResolver =
            $this->createMock(
                ActiveUserResolverInterface::class,
            );

        $activeUserResolver
            ->expects($this->never())
            ->method('findActiveById');

        $query =
            new WorkspaceCapabilityProjectionQuery(
                permissionCatalogQuery: $this->app->make(
                    PermissionCatalogQuery::class,
                ),
                authorizationContextResolver: $authorizationContextResolver,
                organizationalContext: $organizationalContext,
                organizationalContextResolver: $organizationalContextResolver,
                organizationalAuthorizationService: $organizationalAuthorizationService,
                activeUserResolver: $activeUserResolver,
            );

        $this->expectException(
            CapabilityProjectionContextException::class,
        );

        $query->execute();
    }

    public function test_it_fails_closed_when_organizational_context_does_not_match_authenticated_context(): void
    {
        $userId = UuidV7::generate();
        $tenantId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        $currentContext =
            new OrganizationalContext(
                tenantId: $tenantId,
                membershipId: $membershipId,
                assignmentId: UuidV7::generate(),
                organizationId: UuidV7::generate(),
                organizationUnitId: null,
            );

        $resolvedContext =
            new OrganizationalContext(
                tenantId: $tenantId,
                membershipId: UuidV7::generate(),
                assignmentId: $currentContext->assignmentId,
                organizationId: $currentContext->organizationId,
                organizationUnitId: null,
            );

        $authorizationContextResolver =
            $this->createMock(
                AuthorizationContextResolverInterface::class,
            );

        $authorizationContextResolver
            ->expects($this->once())
            ->method('resolve')
            ->willReturn(
                new AuthorizationContext(
                    userId: $userId,
                    tenantId: $tenantId,
                    membershipId: $membershipId,
                ),
            );

        $organizationalContext =
            $this->createMock(
                OrganizationalContextInterface::class,
            );

        $organizationalContext
            ->expects($this->once())
            ->method('getCurrentContext')
            ->willReturn($currentContext);

        $organizationalContextResolver =
            $this->createMock(
                OrganizationalContextResolverInterface::class,
            );

        $organizationalContextResolver
            ->expects($this->once())
            ->method('resolve')
            ->with(
                $currentContext->assignmentId,
            )
            ->willReturn($resolvedContext);

        $organizationalAuthorizationService =
            $this->createMock(
                OrganizationalAuthorizationServiceInterface::class,
            );

        $organizationalAuthorizationService
            ->expects($this->never())
            ->method('hasPermission');

        $activeUserResolver =
            $this->createMock(
                ActiveUserResolverInterface::class,
            );

        $activeUserResolver
            ->expects($this->never())
            ->method('findActiveById');

        $query =
            new WorkspaceCapabilityProjectionQuery(
                permissionCatalogQuery: $this->app->make(
                    PermissionCatalogQuery::class,
                ),
                authorizationContextResolver: $authorizationContextResolver,
                organizationalContext: $organizationalContext,
                organizationalContextResolver: $organizationalContextResolver,
                organizationalAuthorizationService: $organizationalAuthorizationService,
                activeUserResolver: $activeUserResolver,
            );

        $this->expectException(
            CapabilityProjectionContextException::class,
        );

        $query->execute();
    }

    public function test_it_fails_closed_when_organizational_assignment_can_no_longer_be_resolved(): void
    {
        $authorizationContext =
            new AuthorizationContext(
                userId: UuidV7::generate(),
                tenantId: UuidV7::generate(),
                membershipId: UuidV7::generate(),
            );

        $currentContext =
            new OrganizationalContext(
                tenantId: $authorizationContext->tenantId(),
                membershipId: $authorizationContext->membershipId(),
                assignmentId: UuidV7::generate(),
                organizationId: UuidV7::generate(),
                organizationUnitId: null,
            );

        $authorizationContextResolver =
            $this->createMock(
                AuthorizationContextResolverInterface::class,
            );

        $authorizationContextResolver
            ->expects($this->once())
            ->method('resolve')
            ->willReturn(
                $authorizationContext,
            );

        $organizationalContext =
            $this->createMock(
                OrganizationalContextInterface::class,
            );

        $organizationalContext
            ->expects($this->once())
            ->method('getCurrentContext')
            ->willReturn($currentContext);

        $organizationalContextResolver =
            $this->createMock(
                OrganizationalContextResolverInterface::class,
            );

        $organizationalContextResolver
            ->expects($this->once())
            ->method('resolve')
            ->with(
                $currentContext->assignmentId,
            )
            ->willThrowException(
                new OrganizationalContextException(
                    'stale-assignment',
                ),
            );

        $organizationalAuthorizationService =
            $this->createMock(
                OrganizationalAuthorizationServiceInterface::class,
            );

        $organizationalAuthorizationService
            ->expects($this->never())
            ->method('hasPermission');

        $activeUserResolver =
            $this->createMock(
                ActiveUserResolverInterface::class,
            );

        $activeUserResolver
            ->expects($this->never())
            ->method('findActiveById');

        $query =
            new WorkspaceCapabilityProjectionQuery(
                permissionCatalogQuery: $this->app->make(
                    PermissionCatalogQuery::class,
                ),
                authorizationContextResolver: $authorizationContextResolver,
                organizationalContext: $organizationalContext,
                organizationalContextResolver: $organizationalContextResolver,
                organizationalAuthorizationService: $organizationalAuthorizationService,
                activeUserResolver: $activeUserResolver,
            );

        $this->expectException(
            CapabilityProjectionContextException::class,
        );

        $query->execute();
    }

    /**
     * @param array<int, string> $names
     */
    private function seedPermissions(
        array $names,
    ): void {
        foreach ($names as $name) {
            Permission::query()->create([
                'name' => $name,
                'display_name' =>
                ucwords(
                    str_replace(
                        '.',
                        ' ',
                        $name,
                    ),
                ),
                'description' => null,
                'module' => 'Core',
            ]);
        }
    }

    private function user(
        string $id,
        bool $isGlobalSuperadmin,
    ): User {
        $user = new User();

        $user->forceFill([
            'id' => $id,
            'status' => 'ACTIVE',
            'is_superadmin' =>
            $isGlobalSuperadmin,
        ]);

        return $user;
    }

    /**
     * @param array<int, string> $effectivePermissions
     */
    private function makeQuery(
        AuthorizationContext $authorizationContext,
        OrganizationalContext $currentOrganizationalContext,
        OrganizationalContext $resolvedOrganizationalContext,
        User $user,
        array $effectivePermissions,
    ): WorkspaceCapabilityProjectionQuery {
        $authorizationContextResolver =
            $this->createMock(
                AuthorizationContextResolverInterface::class,
            );

        $authorizationContextResolver
            ->expects($this->once())
            ->method('resolve')
            ->willReturn(
                $authorizationContext,
            );

        $organizationalContext =
            $this->createMock(
                OrganizationalContextInterface::class,
            );

        $organizationalContext
            ->expects($this->once())
            ->method('getCurrentContext')
            ->willReturn(
                $currentOrganizationalContext,
            );

        $organizationalContextResolver =
            $this->createMock(
                OrganizationalContextResolverInterface::class,
            );

        $organizationalContextResolver
            ->expects($this->once())
            ->method('resolve')
            ->with(
                $currentOrganizationalContext
                    ->assignmentId,
            )
            ->willReturn(
                $resolvedOrganizationalContext,
            );

        $activeUserResolver =
            $this->createMock(
                ActiveUserResolverInterface::class,
            );

        $activeUserResolver
            ->expects($this->once())
            ->method('findActiveById')
            ->with(
                $authorizationContext->userId(),
            )
            ->willReturn($user);

        $organizationalAuthorizationService =
            $this->createMock(
                OrganizationalAuthorizationServiceInterface::class,
            );

        $organizationalAuthorizationService
            ->expects(
                $this->exactly(
                    count(
                        $this->app
                            ->make(
                                PermissionCatalogQuery::class,
                            )
                            ->execute(),
                    ),
                ),
            )
            ->method('hasPermission')
            ->willReturnCallback(
                static fn(
                    string $permissionName,
                ): bool => in_array(
                    $permissionName,
                    $effectivePermissions,
                    true,
                ),
            );

        return new WorkspaceCapabilityProjectionQuery(
            permissionCatalogQuery: $this->app->make(
                PermissionCatalogQuery::class,
            ),
            authorizationContextResolver: $authorizationContextResolver,
            organizationalContext: $organizationalContext,
            organizationalContextResolver: $organizationalContextResolver,
            organizationalAuthorizationService: $organizationalAuthorizationService,
            activeUserResolver: $activeUserResolver,
        );
    }
}
