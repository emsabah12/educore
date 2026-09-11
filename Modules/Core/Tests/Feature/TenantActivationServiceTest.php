<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Authorization\Database\Seeders\AuthorizationCatalogSeeder;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Services\TenantActivationService;
use RuntimeException;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class TenantActivationServiceTest extends TestCase
{
    use GrantsAuthorizationRole;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_activate_creates_default_organization_named_after_tenant(): void
    {
        $tenantId = $this->createTenantFixture(
            'Kampus Merdeka',
        );

        $organization = $this->service()->activate(
            $tenantId,
        );

        $this->assertNotNull(
            $organization,
        );
        $this->assertSame(
            'Kampus Merdeka',
            $organization->name,
        );
        $this->assertNull(
            $organization->code,
        );
        $this->assertTrue(
            $organization->is_active,
        );

        $this->assertDatabaseHas('organizations', [
            'tenant_id' => $tenantId,
            'name' => 'Kampus Merdeka',
        ]);
    }

    public function test_activate_assigns_all_active_admin_memberships_to_the_new_organization(): void
    {
        $tenantId = $this->createTenantFixture(
            'Kampus Merdeka',
        );

        $adminOneId = $this->createMembershipFixture(
            $tenantId,
            'ACTIVE',
        );
        $this->grantRole(
            $adminOneId,
            'admin',
        );

        $adminTwoId = $this->createMembershipFixture(
            $tenantId,
            'ACTIVE',
        );
        $this->grantRole(
            $adminTwoId,
            'admin',
        );

        $organization = $this->service()->activate(
            $tenantId,
        );

        $this->assertNotNull(
            $organization,
        );

        foreach ([$adminOneId, $adminTwoId] as $membershipId) {
            $this->assertDatabaseHas('organizational_assignments', [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'organization_id' => (string) $organization->id,
                'organization_unit_id' => null,
                'status' => 'ACTIVE',
            ]);
        }
    }

    public function test_activate_does_not_assign_non_admin_membership(): void
    {
        $tenantId = $this->createTenantFixture(
            'Kampus Merdeka',
        );

        $nonAdminId = $this->createMembershipFixture(
            $tenantId,
            'ACTIVE',
        );

        $this->service()->activate(
            $tenantId,
        );

        $this->assertDatabaseMissing('organizational_assignments', [
            'membership_id' => $nonAdminId,
        ]);
    }

    public function test_activate_does_not_assign_inactive_admin_membership(): void
    {
        $tenantId = $this->createTenantFixture(
            'Kampus Merdeka',
        );

        $inactiveAdminId = $this->createMembershipFixture(
            $tenantId,
            'INACTIVE',
        );
        $this->grantRole(
            $inactiveAdminId,
            'admin',
        );

        $this->service()->activate(
            $tenantId,
        );

        $this->assertDatabaseMissing('organizational_assignments', [
            'membership_id' => $inactiveAdminId,
        ]);
    }

    public function test_activate_is_a_total_no_op_when_tenant_already_has_an_organization(): void
    {
        $tenantId = $this->createTenantFixture(
            'Kampus Merdeka',
        );

        $existingOrganizationId = UuidV7::generate();
        DB::table('organizations')->insert([
            'id' => $existingOrganizationId,
            'tenant_id' => $tenantId,
            'name' => 'Organisasi Sudah Ada',
            'code' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adminId = $this->createMembershipFixture(
            $tenantId,
            'ACTIVE',
        );
        $this->grantRole(
            $adminId,
            'admin',
        );

        $result = $this->service()->activate(
            $tenantId,
        );

        $this->assertNull(
            $result,
        );

        $this->assertDatabaseCount(
            'organizations',
            1,
        );

        $this->assertDatabaseMissing('organizational_assignments', [
            'membership_id' => $adminId,
        ]);
    }

    public function test_activate_is_idempotent_across_repeated_calls(): void
    {
        $tenantId = $this->createTenantFixture(
            'Kampus Merdeka',
        );

        $adminId = $this->createMembershipFixture(
            $tenantId,
            'ACTIVE',
        );
        $this->grantRole(
            $adminId,
            'admin',
        );

        $first = $this->service()->activate(
            $tenantId,
        );
        $second = $this->service()->activate(
            $tenantId,
        );

        $this->assertNotNull(
            $first,
        );
        $this->assertNull(
            $second,
        );

        $this->assertDatabaseCount(
            'organizations',
            1,
        );

        $this->assertDatabaseCount(
            'organizational_assignments',
            1,
        );
    }

    public function test_activate_succeeds_with_zero_admin_memberships(): void
    {
        $tenantId = $this->createTenantFixture(
            'Kampus Merdeka',
        );

        $organization = $this->service()->activate(
            $tenantId,
        );

        $this->assertNotNull(
            $organization,
        );

        $this->assertDatabaseCount(
            'organizational_assignments',
            0,
        );
    }

    public function test_activate_rejects_unknown_tenant(): void
    {
        $this->expectException(
            RuntimeException::class,
        );

        $this->service()->activate(
            UuidV7::generate(),
        );
    }

    public function test_activate_rejects_inactive_tenant(): void
    {
        $tenantId = $this->createTenantFixture(
            'Kampus Merdeka',
            isActive: false,
        );

        $this->expectException(
            RuntimeException::class,
        );

        $this->service()->activate(
            $tenantId,
        );
    }

    public function test_activate_clears_tenant_context_after_running(): void
    {
        $tenantId = $this->createTenantFixture(
            'Kampus Merdeka',
        );

        $this->service()->activate(
            $tenantId,
        );

        $this->assertNull(
            app(TenantContextInterface::class)->getCurrentTenantId(),
        );
    }

    public function test_activate_clears_tenant_context_even_when_it_throws(): void
    {
        try {
            $this->service()->activate(
                UuidV7::generate(),
            );
        } catch (RuntimeException) {
            // expected — assertion is on context cleanup below.
        }

        $this->assertNull(
            app(TenantContextInterface::class)->getCurrentTenantId(),
        );
    }

    private function service(): TenantActivationService
    {
        return app(TenantActivationService::class);
    }

    private function createTenantFixture(
        string $name,
        bool $isActive = true,
    ): string {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => $name,
            'subdomain' => sprintf(
                'tenant-activation-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createMembershipFixture(
        string $tenantId,
        string $status,
    ): string {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Tenant Activation Fixture Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $membershipId = UuidV7::generate();

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $membershipId;
    }
}
