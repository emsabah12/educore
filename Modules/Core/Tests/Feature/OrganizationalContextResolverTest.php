<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Identity\Models\User;
use Modules\Core\Organization\Context\OrganizationalContext;
use Modules\Core\Organization\Contracts\OrganizationalContextInterface;
use Modules\Core\Organization\Contracts\OrganizationalContextResolverInterface;
use Modules\Core\Organization\Exceptions\OrganizationalContextException;
use Modules\Core\Organization\Models\Organization;
use Modules\Core\Organization\Models\OrganizationalAssignment;
use Modules\Core\Organization\Models\OrganizationUnit;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class OrganizationalContextResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_bindings_are_scoped_and_state_resets_between_scopes(): void
    {
        $state = $this->app->make(
            OrganizationalContextInterface::class,
        );
        $resolver = $this->app->make(
            OrganizationalContextResolverInterface::class,
        );

        $context = new OrganizationalContext(
            tenantId: 'tenant',
            membershipId: 'membership',
            assignmentId: 'assignment',
            organizationId: 'organization',
            organizationUnitId: null,
        );

        $state->setCurrentContext($context);

        $this->assertSame(
            $state,
            $this->app->make(
                OrganizationalContextInterface::class,
            ),
        );
        $this->assertSame(
            $resolver,
            $this->app->make(
                OrganizationalContextResolverInterface::class,
            ),
        );
        $this->assertSame(
            $context,
            $state->getCurrentContext(),
        );

        $this->app->forgetScopedInstances();

        $nextState = $this->app->make(
            OrganizationalContextInterface::class,
        );
        $nextResolver = $this->app->make(
            OrganizationalContextResolverInterface::class,
        );

        $this->assertNotSame($state, $nextState);
        $this->assertNotSame($resolver, $nextResolver);
        $this->assertNull(
            $nextState->getCurrentContext(),
        );
    }

    public function test_resolves_and_stores_organization_level_context(): void
    {
        [$tenant, $user, $membership, $organization] =
            $this->createAuthenticatedPlacement(
                withUnit: false,
            );

        $assignment = $this->createAssignment(
            $tenant,
            $membership,
            $organization,
            null,
        );

        $context = $this->resolver()->resolve(
            (string) $assignment->getKey(),
        );

        $this->assertSame(
            (string) $tenant->getKey(),
            $context->tenantId,
        );
        $this->assertSame(
            (string) $membership->getKey(),
            $context->membershipId,
        );
        $this->assertSame(
            (string) $assignment->getKey(),
            $context->assignmentId,
        );
        $this->assertSame(
            (string) $organization->getKey(),
            $context->organizationId,
        );
        $this->assertNull($context->organizationUnitId);
        $this->assertSame(
            $context,
            $this->state()->getCurrentContext(),
        );
        $this->assertSame(
            (string) $user->getKey(),
            (string) auth()->id(),
        );
    }

    public function test_resolves_unit_level_context(): void
    {
        [$tenant, , $membership, $organization, $unit] =
            $this->createAuthenticatedPlacement(
                withUnit: true,
            );

        $assignment = $this->createAssignment(
            $tenant,
            $membership,
            $organization,
            $unit,
        );

        $context = $this->resolver()->resolve(
            (string) $assignment->getKey(),
        );

        $this->assertSame(
            (string) $unit->getKey(),
            $context->organizationUnitId,
        );
        $this->assertSame(
            (string) $organization->getKey(),
            $context->organizationId,
        );
    }

    public function test_context_switch_changes_runtime_state_without_mutating_assignments(): void
    {
        [$tenant, , $membership, $organizationA] =
            $this->createAuthenticatedPlacement(
                withUnit: false,
            );

        $assignmentA = $this->createAssignment(
            $tenant,
            $membership,
            $organizationA,
            null,
        );

        $organizationB = Organization::query()->create([
            'name' => 'Organization B',
            'is_active' => true,
        ]);

        $assignmentB = $this->createAssignment(
            $tenant,
            $membership,
            $organizationB,
            null,
        );

        $this->resolver()->resolve(
            (string) $assignmentA->getKey(),
        );
        $second = $this->resolver()->resolve(
            (string) $assignmentB->getKey(),
        );

        $this->assertSame(
            (string) $assignmentB->getKey(),
            $this->state()->getCurrentContext()?->assignmentId,
        );
        $this->assertSame(
            OrganizationalAssignment::STATUS_ACTIVE,
            $assignmentA->refresh()->status,
        );
        $this->assertSame(
            OrganizationalAssignment::STATUS_ACTIVE,
            $assignmentB->refresh()->status,
        );
        $this->assertSame(
            (string) $organizationB->getKey(),
            $second->organizationId,
        );
    }

    public function test_failed_context_switch_clears_previous_context(): void
    {
        [$tenant, , $membership, $organization] =
            $this->createAuthenticatedPlacement(
                withUnit: false,
            );

        $assignment = $this->createAssignment(
            $tenant,
            $membership,
            $organization,
            null,
        );

        $this->resolver()->resolve(
            (string) $assignment->getKey(),
        );

        $this->assertNotNull(
            $this->state()->getCurrentContext(),
        );

        try {
            $this->resolver()->resolve('not-a-uuid');
            $this->fail(
                'Invalid context switch must fail closed.',
            );
        } catch (OrganizationalContextException) {
            $this->assertNull(
                $this->state()->getCurrentContext(),
            );
        }
    }

    public function test_rejects_assignment_for_another_membership(): void
    {
        [$tenant, , $membershipA, $organization] =
            $this->createAuthenticatedPlacement(
                withUnit: false,
            );

        $userB = User::factory()->create();
        $membershipB = Membership::query()->create([
            'person_id' => (string) $userB->person_id,
            'tenant_id' => (string) $tenant->getKey(),
            'status' => 'ACTIVE',
        ]);

        $assignmentB = $this->createAssignment(
            $tenant,
            $membershipB,
            $organization,
            null,
        );

        $this->expectException(
            OrganizationalContextException::class,
        );

        $this->resolver()->resolve(
            (string) $assignmentB->getKey(),
        );
    }

    public function test_rejects_assignment_from_another_tenant(): void
    {
        [$tenantA, , , ] =
            $this->createAuthenticatedPlacement(
                withUnit: false,
            );

        $tenantB = $this->createTenant(
            'Context Tenant B',
            'context-tenant-b',
        );
        $userB = User::factory()->create();
        $membershipB = Membership::query()->create([
            'person_id' => (string) $userB->person_id,
            'tenant_id' => (string) $tenantB->getKey(),
            'status' => 'ACTIVE',
        ]);

        $this->activateTenant($tenantB);

        $organizationB = Organization::query()->create([
            'name' => 'Tenant B Organization',
            'is_active' => true,
        ]);

        $assignmentB = $this->createAssignment(
            $tenantB,
            $membershipB,
            $organizationB,
            null,
        );

        $this->activateTenant($tenantA);

        $this->expectException(
            OrganizationalContextException::class,
        );

        $this->resolver()->resolve(
            (string) $assignmentB->getKey(),
        );
    }

    public function test_rejects_inactive_assignment(): void
    {
        [$tenant, , $membership, $organization] =
            $this->createAuthenticatedPlacement(
                withUnit: false,
            );

        $assignment = $this->createAssignment(
            $tenant,
            $membership,
            $organization,
            null,
        );

        $assignment->update([
            'status' => OrganizationalAssignment::STATUS_INACTIVE,
        ]);

        $this->expectException(
            OrganizationalContextException::class,
        );

        $this->resolver()->resolve(
            (string) $assignment->getKey(),
        );
    }

    public function test_rejects_inactive_organization(): void
    {
        [$tenant, , $membership, $organization] =
            $this->createAuthenticatedPlacement(
                withUnit: false,
            );

        $assignment = $this->createAssignment(
            $tenant,
            $membership,
            $organization,
            null,
        );

        $organization->update([
            'is_active' => false,
        ]);

        $this->expectException(
            OrganizationalContextException::class,
        );

        $this->resolver()->resolve(
            (string) $assignment->getKey(),
        );
    }

    public function test_rejects_inactive_unit(): void
    {
        [$tenant, , $membership, $organization, $unit] =
            $this->createAuthenticatedPlacement(
                withUnit: true,
            );

        $assignment = $this->createAssignment(
            $tenant,
            $membership,
            $organization,
            $unit,
        );

        $unit->update([
            'is_active' => false,
        ]);

        $this->expectException(
            OrganizationalContextException::class,
        );

        $this->resolver()->resolve(
            (string) $assignment->getKey(),
        );
    }

    public function test_rejects_inactive_membership(): void
    {
        [$tenant, , $membership, $organization] =
            $this->createAuthenticatedPlacement(
                withUnit: false,
            );

        $assignment = $this->createAssignment(
            $tenant,
            $membership,
            $organization,
            null,
        );

        $membership->update([
            'status' => 'INACTIVE',
        ]);

        $this->expectException(
            OrganizationalContextException::class,
        );

        $this->resolver()->resolve(
            (string) $assignment->getKey(),
        );
    }

    public function test_rejects_inactive_tenant(): void
    {
        [$tenant, , $membership, $organization] =
            $this->createAuthenticatedPlacement(
                withUnit: false,
            );

        $assignment = $this->createAssignment(
            $tenant,
            $membership,
            $organization,
            null,
        );

        $tenant->setAttribute('is_active', false);

        $this->expectException(
            OrganizationalContextException::class,
        );

        $this->resolver()->resolve(
            (string) $assignment->getKey(),
        );
    }

    public function test_rejects_missing_verified_membership_context(): void
    {
        $tenant = $this->createTenant(
            'No Membership Context',
            'no-membership-context',
        );
        $this->activateTenant($tenant);

        $user = User::factory()->create();
        $membership = Membership::query()->create([
            'person_id' => (string) $user->person_id,
            'tenant_id' => (string) $tenant->getKey(),
            'status' => 'ACTIVE',
        ]);

        $organization = Organization::query()->create([
            'name' => 'No Membership Organization',
            'is_active' => true,
        ]);

        $assignment = $this->createAssignment(
            $tenant,
            $membership,
            $organization,
            null,
        );

        $this->actingAs($user);

        request()->attributes->remove(
            'authenticated_membership_id',
        );

        $this->expectException(
            OrganizationalContextException::class,
        );

        $this->resolver()->resolve(
            (string) $assignment->getKey(),
        );
    }

    /**
     * @return array{
     *     0: Tenant,
     *     1: User,
     *     2: Membership,
     *     3: Organization,
     *     4?: OrganizationUnit
     * }
     */
    private function createAuthenticatedPlacement(
        bool $withUnit,
    ): array {
        $tenant = $this->createTenant(
            'Organizational Context Tenant',
            'organizational-context-' . strtolower(
                substr((string) \Illuminate\Support\Str::uuid(), 0, 8),
            ),
        );
        $this->activateTenant($tenant);

        $user = User::factory()->create();

        $membership = Membership::query()->create([
            'person_id' => (string) $user->person_id,
            'tenant_id' => (string) $tenant->getKey(),
            'status' => 'ACTIVE',
        ]);

        $organization = Organization::query()->create([
            'name' => 'Context Organization',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        request()->attributes->set(
            'authenticated_membership_id',
            (string) $membership->getKey(),
        );

        if (! $withUnit) {
            return [
                $tenant,
                $user,
                $membership,
                $organization,
            ];
        }

        $unit = OrganizationUnit::query()->create([
            'organization_id' => (string) $organization->getKey(),
            'name' => 'Context Unit',
            'is_active' => true,
        ]);

        return [
            $tenant,
            $user,
            $membership,
            $organization,
            $unit,
        ];
    }

    private function createTenant(
        string $name,
        string $subdomain,
    ): Tenant {
        return Tenant::query()->create([
            'name' => $name,
            'subdomain' => $subdomain,
            'is_active' => true,
        ]);
    }

    private function createAssignment(
        Tenant $tenant,
        Membership $membership,
        Organization $organization,
        ?OrganizationUnit $unit,
    ): OrganizationalAssignment {
        return OrganizationalAssignment::query()->create([
            'tenant_id' => (string) $tenant->getKey(),
            'membership_id' => (string) $membership->getKey(),
            'organization_id' => (string) $organization->getKey(),
            'organization_unit_id' => $unit !== null
                ? (string) $unit->getKey()
                : null,
            'status' => OrganizationalAssignment::STATUS_ACTIVE,
        ]);
    }

    private function activateTenant(
        Tenant $tenant,
    ): void {
        $this->tenantContext()->setCurrentTenant($tenant);
    }

    private function tenantContext(): TenantContextInterface
    {
        return $this->app->make(
            TenantContextInterface::class,
        );
    }

    private function resolver(): OrganizationalContextResolverInterface
    {
        return $this->app->make(
            OrganizationalContextResolverInterface::class,
        );
    }

    private function state(): OrganizationalContextInterface
    {
        return $this->app->make(
            OrganizationalContextInterface::class,
        );
    }
}
