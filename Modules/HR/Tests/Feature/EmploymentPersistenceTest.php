<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Models\Employee;
use Modules\HR\Models\Employment;
use Tests\TestCase;

final class EmploymentPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantAId;
    private string $tenantBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantAId = $this->createTenant('Employment Tenant A');
        $this->tenantBId = $this->createTenant('Employment Tenant B');
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_planned_employment_can_be_created_without_end_date_or_cancelled_at(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $employeeId = $this->createEmployee($this->tenantAId);

        $employment = Employment::create([
            'employee_id' => $employeeId,
            'status' => Employment::STATUS_PLANNED,
            'start_date' => '2026-09-01',
        ]);

        $this->assertTrue(Str::isUuid($employment->id));
        $this->assertSame(Employment::STATUS_PLANNED, $employment->status);
        $this->assertNull($employment->end_date);
        $this->assertNull($employment->cancelled_at);
    }

    /**
     * INV-HR-002 (HR-002 §6.1 & §9.1): partial unique index adalah garda
     * terakhir yang mencegah dua Employment ACTIVE untuk Employee yang
     * sama, bahkan kalau ada race condition di level aplikasi.
     */
    public function test_database_rejects_second_active_employment_for_same_employee(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $employeeId = $this->createEmployee($this->tenantAId);

        Employment::create([
            'employee_id' => $employeeId,
            'status' => Employment::STATUS_ACTIVE,
            'start_date' => '2023-01-01',
        ]);

        $this->expectException(QueryException::class);

        Employment::create([
            'employee_id' => $employeeId,
            'status' => Employment::STATUS_ACTIVE,
            'start_date' => '2026-01-01',
        ]);
    }

    public function test_employee_may_have_multiple_non_active_employments(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $employeeId = $this->createEmployee($this->tenantAId);

        Employment::create([
            'employee_id' => $employeeId,
            'status' => Employment::STATUS_ENDED,
            'start_date' => '2020-01-01',
            'end_date' => '2022-12-31',
        ]);

        $secondEmployment = Employment::create([
            'employee_id' => $employeeId,
            'status' => Employment::STATUS_ACTIVE,
            'start_date' => '2023-01-01',
        ]);

        $this->assertSame(
            2,
            Employment::query()
                ->where('employee_id', $employeeId)
                ->count(),
        );
        $this->assertSame(
            Employment::STATUS_ACTIVE,
            $secondEmployment->status,
        );
    }

    public function test_check_constraint_rejects_ended_employment_without_end_date(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $employeeId = $this->createEmployee($this->tenantAId);

        $this->expectException(QueryException::class);

        Employment::create([
            'employee_id' => $employeeId,
            'status' => Employment::STATUS_ENDED,
            'start_date' => '2020-01-01',
            'end_date' => null,
        ]);
    }

    public function test_check_constraint_rejects_cancelled_employment_without_cancelled_at(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $employeeId = $this->createEmployee($this->tenantAId);

        $this->expectException(QueryException::class);

        Employment::create([
            'employee_id' => $employeeId,
            'status' => Employment::STATUS_CANCELLED,
            'start_date' => '2020-01-01',
            'cancelled_at' => null,
        ]);
    }

    public function test_check_constraint_rejects_end_date_before_start_date(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $employeeId = $this->createEmployee($this->tenantAId);

        $this->expectException(QueryException::class);

        Employment::create([
            'employee_id' => $employeeId,
            'status' => Employment::STATUS_ENDED,
            'start_date' => '2026-01-01',
            'end_date' => '2025-01-01',
        ]);
    }

    public function test_check_constraint_rejects_unknown_status_value(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $employeeId = $this->createEmployee($this->tenantAId);

        $this->expectException(QueryException::class);

        Employment::create([
            'employee_id' => $employeeId,
            'status' => 'ON_LEAVE_FOREVER',
            'start_date' => '2026-01-01',
        ]);
    }

    /**
     * Composite FK (employee_id, tenant_id) → employees(id, tenant_id)
     * harus menolak Employment yang mereferensikan Employee dari tenant
     * lain, walaupun employee_id-nya valid secara UUID.
     */
    public function test_composite_foreign_key_rejects_employee_from_another_tenant(): void
    {
        $employeeFromTenantB = $this->createEmployee($this->tenantBId);

        $this->activateTenantContext($this->tenantAId);

        $this->expectException(QueryException::class);

        Employment::create([
            'employee_id' => $employeeFromTenantB,
            'status' => Employment::STATUS_PLANNED,
            'start_date' => '2026-01-01',
        ]);
    }

    public function test_employee_employments_relation_returns_owned_episodes(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $employeeId = $this->createEmployee($this->tenantAId);

        Employment::create([
            'employee_id' => $employeeId,
            'status' => Employment::STATUS_ACTIVE,
            'start_date' => '2026-01-01',
        ]);

        /** @var Employee $employee */
        $employee = Employee::query()->findOrFail($employeeId);

        $this->assertCount(1, $employee->employments);
        $this->assertSame(
            Employment::STATUS_ACTIVE,
            $employee->employments->first()->status,
        );
    }

    private function activateTenantContext(string $tenantId): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($tenantId);

        app(TenantContextInterface::class)->setCurrentTenant($tenant);
    }

    private function createTenant(string $name): string
    {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => $name,
            'subdomain' => sprintf(
                'employment-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createEmployee(string $tenantId): string
    {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Employment Fixture Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = UuidV7::generate();

        DB::table('employees')->insert([
            'id' => $employeeId,
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'nip' => sprintf('NIP-%s', Str::upper(Str::random(8))),
            'jabatan' => 'GURU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employeeId;
    }
}
