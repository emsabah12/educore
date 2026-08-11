<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Database\Seeders\AuthorizationCatalogSeeder;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRoleRepositoryInterface;
use Modules\Core\Identity\Models\User;
use Modules\Core\Person\Models\PersonModel;
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
