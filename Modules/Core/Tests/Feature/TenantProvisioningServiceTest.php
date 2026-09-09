<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Authorization\Database\Seeders\AuthorizationCatalogSeeder;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;
use Modules\Core\Identity\Models\User;
use Modules\Core\Person\Models\PersonModel;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Exceptions\InvalidInitialTenantAdminException;
use Modules\Core\Tenancy\Services\TenantProvisioningService;
use RuntimeException;
use Tests\TestCase;

final class TenantProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            AuthorizationCatalogSeeder::class,
        );
    }

    public function test_service_atomically_provisions_tenant_membership_and_admin_role(): void
    {
        $user = User::factory()->create();

        $service = $this->app->make(
            TenantProvisioningService::class,
        );

        $result = $service->provision(
            [
                'name' => 'Sekolah Provisioning',
                'subdomain' => 'sekolah-provisioning',
                'is_active' => true,
                'settings' => [],
            ],
            (string) $user->id,
        );

        $tenantId = (string) $result['tenant']['id'];
        $membershipId =
            $result['initial_admin']['membership_id'];

        $this->assertSame(
            (string) $user->id,
            $result['initial_admin']['user_id'],
        );

        $this->assertSame(
            (string) $user->person_id,
            $result['initial_admin']['person_id'],
        );

        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'subdomain' => 'sekolah-provisioning',
        ]);

        $this->assertDatabaseHas('memberships', [
            'id' => $membershipId,
            'person_id' => (string) $user->person_id,
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
        ]);

        $adminRoleId = DB::table('roles')
            ->where('name', 'admin')
            ->value('id');

        $this->assertIsString($adminRoleId);

        $this->assertDatabaseHas('membership_roles', [
            'membership_id' => $membershipId,
            'role_id' => $adminRoleId,
        ]);
    }

    public function test_service_resolves_canonical_admin_role_even_when_another_tenant_has_a_custom_role_with_the_same_name(): void
    {
        $otherTenantId = (string) UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $otherTenantId,
            'name' => 'Provisioning Shadow Admin Tenant',
            'subdomain' => 'provisioning-shadow-admin-tenant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Role KUSTOM milik tenant lain, sengaja bernama sama persis
        // dengan role sistem "admin" — ini sah menurut partial unique
        // index (unik per tenant_id), tapi TIDAK BOLEH pernah
        // tertukar dengan role admin kanonik.
        DB::table('roles')->insert([
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $otherTenantId,
            'name' => 'admin',
            'display_name' => 'Admin Palsu Tenant Lain',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $canonicalAdminRoleId = DB::table('roles')
            ->whereNull('tenant_id')
            ->where('name', 'admin')
            ->value('id');

        $user = User::factory()->create();

        $service = $this->app->make(
            TenantProvisioningService::class,
        );

        $result = $service->provision(
            [
                'name' => 'Sekolah Provisioning Aman',
                'subdomain' => 'sekolah-provisioning-aman',
                'is_active' => true,
                'settings' => [],
            ],
            (string) $user->id,
        );

        $membershipId = $result['initial_admin']['membership_id'];

        $this->assertDatabaseHas('membership_roles', [
            'membership_id' => $membershipId,
            'role_id' => $canonicalAdminRoleId,
        ]);
    }

    public function test_service_provisions_tenant_with_brand_new_admin_account(): void
    {
        $service = $this->app->make(
            TenantProvisioningService::class,
        );

        $result = $service->provisionWithNewAdmin(
            [
                'name' => 'Sekolah Admin Baru',
                'subdomain' => 'sekolah-admin-baru',
                'is_active' => true,
            ],
            [
                'name' => 'Kepala Sekolah Baru',
                'email' => 'kepala.sekolah.baru@educore.test',
                'password' => 'rahasia-yang-kuat',
            ],
        );

        $tenantId = (string) $result['tenant']['id'];
        $userId = $result['initial_admin']['user_id'];
        $membershipId = $result['initial_admin']['membership_id'];

        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'subdomain' => 'sekolah-admin-baru',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => 'kepala.sekolah.baru@educore.test',
        ]);

        $storedUser = User::query()->findOrFail($userId);

        // Password TIDAK PERNAH disimpan sebagai teks polos — cast
        // 'hashed' pada model User yang bertanggung jawab, bukan
        // Hash::make() manual di service.
        $this->assertNotSame('rahasia-yang-kuat', $storedUser->getAttributes()['password']);
        $this->assertTrue(Hash::check('rahasia-yang-kuat', $storedUser->getAttributes()['password']));

        $this->assertDatabaseHas('memberships', [
            'id' => $membershipId,
            'person_id' => (string) $storedUser->person_id,
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
        ]);

        $adminRoleId = DB::table('roles')
            ->where('name', 'admin')
            ->value('id');

        $this->assertDatabaseHas('membership_roles', [
            'membership_id' => $membershipId,
            'role_id' => $adminRoleId,
        ]);
    }

    public function test_service_provisions_new_admin_can_authenticate_with_the_provided_password(): void
    {
        $service = $this->app->make(
            TenantProvisioningService::class,
        );

        $service->provisionWithNewAdmin(
            [
                'name' => 'Sekolah Login Baru',
                'subdomain' => 'sekolah-login-baru',
                'is_active' => true,
            ],
            [
                'name' => 'Admin Login Baru',
                'email' => 'admin.login.baru@educore.test',
                'password' => 'kata-sandi-asli',
            ],
        );

        $storedUser = User::query()
            ->where('email', 'admin.login.baru@educore.test')
            ->firstOrFail();

        $this->assertTrue(
            Hash::check('kata-sandi-asli', $storedUser->getAttributes()['password']),
        );
    }

    public function test_service_rejects_inactive_user_before_creating_tenant(): void
    {
        $user = User::factory()->create([
            'status' => 'SUSPENDED',
        ]);

        $service = $this->app->make(
            TenantProvisioningService::class,
        );

        try {
            $service->provision(
                [
                    'name' => 'Tenant Inactive User',
                    'subdomain' => 'inactive-user',
                ],
                (string) $user->id,
            );

            $this->fail(
                'Inactive User must not be accepted as initial tenant admin.',
            );
        } catch (InvalidInitialTenantAdminException) {
            $this->assertDatabaseMissing('tenants', [
                'subdomain' => 'inactive-user',
            ]);
        }
    }

    public function test_service_rejects_inactive_person_before_creating_tenant(): void
    {
        $person = PersonModel::factory()->create([
            'status' => 'INACTIVE',
        ]);

        $user = User::factory()->create([
            'person_id' => (string) $person->id,
            'status' => 'ACTIVE',
        ]);

        $service = $this->app->make(
            TenantProvisioningService::class,
        );

        try {
            $service->provision(
                [
                    'name' => 'Tenant Inactive Person',
                    'subdomain' => 'inactive-person',
                ],
                (string) $user->id,
            );

            $this->fail(
                'Inactive Person must not be accepted as initial tenant admin.',
            );
        } catch (InvalidInitialTenantAdminException) {
            $this->assertDatabaseMissing('tenants', [
                'subdomain' => 'inactive-person',
            ]);
        }
    }

    public function test_service_fails_closed_when_admin_role_is_missing(): void
    {
        $user = User::factory()->create();

        DB::table('roles')
            ->where('name', 'admin')
            ->delete();

        $service = $this->app->make(
            TenantProvisioningService::class,
        );

        $this->expectException(
            RuntimeException::class,
        );

        try {
            $service->provision(
                [
                    'name' => 'Tenant Missing Role',
                    'subdomain' => 'missing-role',
                ],
                (string) $user->id,
            );
        } finally {
            $this->assertDatabaseMissing('tenants', [
                'subdomain' => 'missing-role',
            ]);
        }
    }

    public function test_role_assignment_failure_rolls_back_tenant_and_membership(): void
    {
        $user = User::factory()->create();

        $membershipRoleRepository = $this->createMock(
            MembershipRoleRepositoryInterface::class,
        );

        $membershipRoleRepository
            ->expects($this->once())
            ->method('assignRole')
            ->willThrowException(
                new RuntimeException(
                    'Simulated role assignment failure.',
                ),
            );

        $this->app->instance(
            MembershipRoleRepositoryInterface::class,
            $membershipRoleRepository,
        );

        $service = $this->app->make(
            TenantProvisioningService::class,
        );

        $this->expectException(
            RuntimeException::class,
        );

        try {
            $service->provision(
                [
                    'name' => 'Tenant Rollback',
                    'subdomain' => 'tenant-rollback',
                ],
                (string) $user->id,
            );
        } finally {
            $this->assertDatabaseMissing('tenants', [
                'subdomain' => 'tenant-rollback',
            ]);

            $this->assertSame(
                0,
                DB::table('memberships')->count(),
            );
        }
    }
}
