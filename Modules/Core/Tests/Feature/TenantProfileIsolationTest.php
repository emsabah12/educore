<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Models\Employee;
use Tests\TestCase;

final class TenantProfileIsolationTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantA;

    private string $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Str::uuid()->toString();
        $this->tenantB = Str::uuid()->toString();

        DB::table('tenants')->insert([
            [
                'id' => $this->tenantA,
                'name' => 'Sekolah Pusat A',
                'subdomain' => 'pusata',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->tenantB,
                'name' => 'Sekolah Cabang B',
                'subdomain' => 'cabangb',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (app()->bound(TenantContextInterface::class)) {
            app(TenantContextInterface::class)->clear();
        }

        parent::tearDown();
    }

    /**
     * Ensure employee profiles are strictly isolated between tenants.
     */
    public function test_it_strictly_isolates_employee_profiles_between_tenants(): void
    {
        $tenantContext = app(TenantContextInterface::class);

        $tenantA = Tenant::query()->findOrFail($this->tenantA);
        $tenantB = Tenant::query()->findOrFail($this->tenantB);

        // ---------------------------------------------------------
        // Tenant A
        // ---------------------------------------------------------

        $userIdA = Str::uuid()->toString();
        $membershipIdA = Str::uuid()->toString();

        DB::table('users')->insert([
            'id' => $userIdA,
            'name' => 'User A',
            'email' => 'user_a@educore.id',
            'password' => 'secret',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipIdA,
            'user_id' => $userIdA,
            'tenant_id' => $this->tenantA,
            'role' => 'employee',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenantContext->setCurrentTenant($tenantA);

        $employeeA = new Employee();
        $employeeA->id = Str::uuid()->toString();
        $employeeA->membership_id = $membershipIdA;
        $employeeA->nip = '19900101AA';
        $employeeA->jabatan = 'GURU';
        $employeeA->save();

        $this->assertEquals(
            $this->tenantA,
            $employeeA->tenant_id,
        );

        // ---------------------------------------------------------
        // Tenant B
        // ---------------------------------------------------------

        $userIdB = Str::uuid()->toString();
        $membershipIdB = Str::uuid()->toString();

        DB::table('users')->insert([
            'id' => $userIdB,
            'name' => 'User B',
            'email' => 'user_b@educore.id',
            'password' => 'secret',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipIdB,
            'user_id' => $userIdB,
            'tenant_id' => $this->tenantB,
            'role' => 'employee',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenantContext->setCurrentTenant($tenantB);

        $employeeB = new Employee();
        $employeeB->id = Str::uuid()->toString();
        $employeeB->membership_id = $membershipIdB;
        $employeeB->nip = '19950202BB';
        $employeeB->jabatan = 'STAFF';
        $employeeB->save();

        $this->assertEquals(
            $this->tenantB,
            $employeeB->tenant_id,
        );

        // ---------------------------------------------------------
        // Verify Tenant B isolation
        // ---------------------------------------------------------

        $studentsInTenantB = Employee::query()->get();

        $this->assertCount(
            1,
            $studentsInTenantB,
        );

        $this->assertEquals(
            '19950202BB',
            $studentsInTenantB->first()->nip,
        );

        // ---------------------------------------------------------
        // Verify Tenant A isolation
        // ---------------------------------------------------------

        $tenantContext->setCurrentTenant($tenantA);

        $employeesInTenantA = Employee::query()->get();

        $this->assertCount(
            1,
            $employeesInTenantA,
        );

        $this->assertEquals(
            '19900101AA',
            $employeesInTenantA->first()->nip,
        );
    }

    /**
     * Ensure employee profiles are removed when their membership
     * is deleted through the database foreign-key cascade.
     */
    public function test_it_cascades_delete_on_profile_when_membership_is_removed(): void
    {
        $tenantContext = app(TenantContextInterface::class);

        $tenantA = Tenant::query()->findOrFail($this->tenantA);

        $userId = Str::uuid()->toString();
        $membershipId = Str::uuid()->toString();

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Employee Test',
            'email' => 'employee_test@educore.id',
            'password' => 'secret',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'user_id' => $userId,
            'tenant_id' => $this->tenantA,
            'role' => 'employee',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenantContext->setCurrentTenant($tenantA);

        $employee = new Employee();
        $employee->id = Str::uuid()->toString();
        $employee->membership_id = $membershipId;
        $employee->nip = '20269999';
        $employee->jabatan = 'GURU';
        $employee->save();

        $this->assertDatabaseHas(
            'employees',
            [
                'id' => $employee->id,
            ],
        );

        DB::table('memberships')
            ->where('id', $membershipId)
            ->delete();

        $this->assertDatabaseMissing(
            'employees',
            [
                'id' => $employee->id,
            ],
        );
    }
}
