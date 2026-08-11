<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Authorization\Contracts\MembershipContextResolverInterface;
use Modules\Core\Authorization\Exceptions\MembershipContextResolutionException;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Identity\Models\User;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class MembershipContextResolverTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $user;
    private Membership $membershipA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::query()->create([
            'name' => 'Tenant Membership A',
            'subdomain' => 'membership-a',
            'is_active' => true,
        ]);

        $this->tenantB = Tenant::query()->create([
            'name' => 'Tenant Membership B',
            'subdomain' => 'membership-b',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create();

        $this->membershipA = Membership::query()->create([
            'person_id' => $this->user->person_id,
            'tenant_id' => $this->tenantA->getKey(),
            'status' => 'ACTIVE',
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();
        auth()->forgetGuards();

        parent::tearDown();
    }

    public function test_it_resolves_explicit_authenticated_membership_context(): void
    {
        $this->actingAs($this->user);
        $this->setTenantContext($this->tenantA);
        request()->attributes->set(
            'authenticated_membership_id',
            (string) $this->membershipA->getKey(),
        );

        $context = $this->resolver()->resolve();

        $this->assertSame(
            (string) $this->user->getKey(),
            $context->userId,
        );
        $this->assertSame(
            (string) $this->tenantA->getKey(),
            $context->tenantId,
        );
        $this->assertSame(
            (string) $this->membershipA->getKey(),
            $context->membershipId,
        );
    }

    public function test_it_rejects_missing_authenticated_membership_context(): void
    {
        $this->actingAs($this->user);
        $this->setTenantContext($this->tenantA);

        $this->expectException(
            MembershipContextResolutionException::class,
        );
        $this->expectExceptionMessage(
            'authenticated membership identifier is required',
        );

        $this->resolver()->resolve();
    }

    public function test_it_rejects_membership_from_another_tenant(): void
    {
        $this->actingAs($this->user);
        $this->setTenantContext($this->tenantB);
        request()->attributes->set(
            'authenticated_membership_id',
            (string) $this->membershipA->getKey(),
        );

        $this->expectException(
            MembershipContextResolutionException::class,
        );
        $this->expectExceptionMessage(
            'active membership was not found',
        );

        $this->resolver()->resolve();
    }

    public function test_it_rejects_membership_owned_by_another_person(): void
    {
        $otherUser = User::factory()->create();
        $otherMembership = Membership::query()->create([
            'person_id' => $otherUser->person_id,
            'tenant_id' => $this->tenantA->getKey(),
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($this->user);
        $this->setTenantContext($this->tenantA);
        request()->attributes->set(
            'authenticated_membership_id',
            (string) $otherMembership->getKey(),
        );

        $this->expectException(
            MembershipContextResolutionException::class,
        );
        $this->expectExceptionMessage(
            'does not belong to the authenticated person and tenant',
        );

        $this->resolver()->resolve();
    }

    public function test_it_rejects_inactive_membership(): void
    {
        $this->membershipA->forceFill([
            'status' => 'SUSPENDED',
        ])->save();

        $this->actingAs($this->user);
        $this->setTenantContext($this->tenantA);
        request()->attributes->set(
            'authenticated_membership_id',
            (string) $this->membershipA->getKey(),
        );

        $this->expectException(
            MembershipContextResolutionException::class,
        );
        $this->expectExceptionMessage(
            'active membership was not found',
        );

        $this->resolver()->resolve();
    }

    public function test_it_rejects_missing_tenant_context(): void
    {
        $this->actingAs($this->user);

        $this->expectException(
            MembershipContextResolutionException::class,
        );
        $this->expectExceptionMessage(
            'tenant context has not been resolved',
        );

        $this->resolver()->resolve();
    }

    public function test_it_rejects_missing_canonical_authenticated_user(): void
    {
        $this->setTenantContext($this->tenantA);

        $this->expectException(
            MembershipContextResolutionException::class,
        );
        $this->expectExceptionMessage(
            'canonical authenticated user is required',
        );

        $this->resolver()->resolve();
    }

    private function resolver(): MembershipContextResolverInterface
    {
        return app(MembershipContextResolverInterface::class);
    }

    private function setTenantContext(Tenant $tenant): void
    {
        app(TenantContextInterface::class)
            ->setCurrentTenant($tenant);
    }
}
