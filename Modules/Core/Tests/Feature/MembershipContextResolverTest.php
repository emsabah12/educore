<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Authorization\Contracts\MembershipContextResolverInterface;
use Modules\Core\Authorization\Exceptions\MembershipContextResolutionException;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class MembershipContextResolverTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantA;
    private string $tenantB;
    private string $userId;
    private string $membershipA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Str::uuid()->toString();
        $this->tenantB = Str::uuid()->toString();
        $this->userId = Str::uuid()->toString();
        $this->membershipA = Str::uuid()->toString();

        DB::table('tenants')->insert([
            [
                'id' => $this->tenantA,
                'name' => 'Tenant Membership A',
                'subdomain' => 'membership-a',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->tenantB,
                'name' => 'Tenant Membership B',
                'subdomain' => 'membership-b',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('users')->insert([
            'id' => $this->userId,
            'name' => 'Membership Context User',
            'email' => 'membership-context@educore.id',
            'password' => 'secret',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $this->membershipA,
            'user_id' => $this->userId,
            'tenant_id' => $this->tenantA,
            'role' => 'employee',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_it_rejects_missing_authenticated_membership_context(): void
    {
        $this->authenticateAsUser(
            $this->userId,
        );

        $this->setTenantContext(
            $this->tenantA,
        );

        $resolver = app(
            MembershipContextResolverInterface::class,
        );

        $this->expectException(
            MembershipContextResolutionException::class,
        );

        $this->expectExceptionMessage(
            'Cannot resolve membership context: authenticated membership identifier is required.',
        );

        $resolver->resolve();
    }

    public function test_it_rejects_user_without_membership_in_current_tenant(): void
    {
        $this->authenticateAsUser(
            $this->userId,
        );

        $this->setTenantContext(
            $this->tenantB,
        );

        /*
     * Authentication context membawa membership A,
     * tetapi current tenant adalah B.
     *
     * Exact tenant-bound lookup harus menolak membership tersebut.
     */
        request()->attributes->set(
            'authenticated_membership_id',
            $this->membershipA,
        );

        $resolver = app(
            MembershipContextResolverInterface::class,
        );

        $this->expectException(
            MembershipContextResolutionException::class,
        );

        $this->expectExceptionMessage(
            'Cannot resolve membership context: active membership was not found.',
        );

        $resolver->resolve();
    }

    public function test_it_rejects_missing_tenant_context(): void
    {
        $this->authenticateAsUser($this->userId);

        app(TenantContextInterface::class)->clear();

        $resolver = app(MembershipContextResolverInterface::class);

        $this->expectException(
            MembershipContextResolutionException::class,
        );

        $this->expectExceptionMessage(
            'Cannot resolve membership context: tenant context has not been resolved.',
        );

        $resolver->resolve();
    }

    public function test_it_rejects_missing_authenticated_user(): void
    {
        $this->setTenantContext($this->tenantA);

        $resolver = app(MembershipContextResolverInterface::class);

        $this->expectException(
            MembershipContextResolutionException::class,
        );

        $this->expectExceptionMessage(
            'Cannot resolve membership context: authenticated user is required.',
        );

        $resolver->resolve();
    }

    public function test_it_rejects_membership_owned_by_another_user(): void
    {
        $otherUserId = Str::uuid()->toString();
        $otherMembershipId = Str::uuid()->toString();

        DB::table('users')->insert([
            'id' => $otherUserId,
            'name' => 'Other Membership User',
            'email' => 'other-membership@educore.id',
            'password' => 'secret',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $otherMembershipId,
            'user_id' => $otherUserId,
            'tenant_id' => $this->tenantA,
            'role' => 'employee',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->authenticateAsUser($this->userId);
        $this->setTenantContext($this->tenantA);

        request()->attributes->set(
            'authenticated_membership_id',
            $otherMembershipId,
        );

        $resolver = app(MembershipContextResolverInterface::class);

        $this->expectException(
            MembershipContextResolutionException::class,
        );

        $this->expectExceptionMessage(
            'Cannot resolve membership context: requested membership is not active or does not belong to the authenticated user and tenant.',
        );

        $resolver->resolve();
    }

    public function test_it_rejects_inactive_membership(): void
    {
        DB::table('memberships')
            ->where('id', $this->membershipA)
            ->update([
                'status' => 'SUSPENDED',
                'updated_at' => now(),
            ]);

        $this->authenticateAsUser($this->userId);
        $this->setTenantContext($this->tenantA);

        request()->attributes->set(
            'authenticated_membership_id',
            $this->membershipA,
        );

        $resolver = app(MembershipContextResolverInterface::class);

        $this->expectException(
            MembershipContextResolutionException::class,
        );

        $this->expectExceptionMessage(
            'Cannot resolve membership context: active membership was not found.',
        );

        $resolver->resolve();
    }

    private function authenticateAsUser(string $userId): void
    {
        $user = DB::table('users')
            ->where('id', $userId)
            ->first();

        $this->assertNotNull($user);

        $this->actingAs(
            new GenericUser((array) $user),
        );
    }

    private function setTenantContext(string $tenantId): void
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        app(TenantContextInterface::class)
            ->setCurrentTenant($tenant);
    }



    public function test_it_resolves_explicit_authenticated_membership_context(): void
    {
        $this->authenticateAsUser(
            $this->userId,
        );

        $this->setTenantContext(
            $this->tenantA,
        );


        request()->attributes->set(
            'authenticated_membership_id',
            $this->membershipA,
        );


        $resolver = app(
            MembershipContextResolverInterface::class,
        );

        $context = $resolver->resolve();

        $this->assertSame(
            $this->userId,
            $context->userId,
        );

        $this->assertSame(
            $this->tenantA,
            $context->tenantId,
        );

        $this->assertSame(
            $this->membershipA,
            $context->membershipId,
        );
    }

    public function test_it_rejects_invalid_authenticated_membership_context(): void
    {
        $otherUserId = Str::uuid()->toString();
        $otherMembershipId = Str::uuid()->toString();

        DB::table('users')->insert([
            'id' => $otherUserId,
            'name' => 'Invalid Token Membership User',
            'email' => 'invalid-token-membership@educore.id',
            'password' => 'secret',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $otherMembershipId,
            'user_id' => $otherUserId,
            'tenant_id' => $this->tenantA,
            'role' => 'employee',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->authenticateAsUser($this->userId);
        $this->setTenantContext($this->tenantA);

        request()->attributes->set(
            'authenticated_membership_id',
            $otherMembershipId,
        );

        $resolver = app(
            MembershipContextResolverInterface::class,
        );

        $this->expectException(
            MembershipContextResolutionException::class,
        );

        $this->expectExceptionMessage(
            'Cannot resolve membership context: requested membership is not active or does not belong to the authenticated user and tenant.',
        );

        $resolver->resolve();
    }
}
