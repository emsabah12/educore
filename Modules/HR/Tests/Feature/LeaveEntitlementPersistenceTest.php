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
use Modules\HR\Models\LeaveEntitlement;
use Modules\HR\Models\LeaveType;
use Tests\TestCase;

final class LeaveEntitlementPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantAId;

    private string $tenantBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantAId = $this->createTenant();
        $this->tenantBId = $this->createTenant();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_entitlement_can_be_created_with_default_active_status(): void
    {
        $this->activateTenantContext($this->tenantAId);
        [$employmentId, $leaveTypeId] = $this->createEmploymentAndLeaveType();

        $entitlement = LeaveEntitlement::create([
            'employment_id' => $employmentId,
            'leave_type_id' => $leaveTypeId,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]);

        $this->assertTrue(Str::isUuid($entitlement->id));
        $this->assertSame(LeaveEntitlement::STATUS_ACTIVE, $entitlement->status);
    }

    public function test_database_rejects_duplicate_period_for_same_employment_and_leave_type(): void
    {
        $this->activateTenantContext($this->tenantAId);
        [$employmentId, $leaveTypeId] = $this->createEmploymentAndLeaveType();

        LeaveEntitlement::create([
            'employment_id' => $employmentId,
            'leave_type_id' => $leaveTypeId,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]);

        $this->expectException(QueryException::class);

        LeaveEntitlement::create([
            'employment_id' => $employmentId,
            'leave_type_id' => $leaveTypeId,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]);
    }

    public function test_check_constraint_rejects_period_end_before_period_start(): void
    {
        $this->activateTenantContext($this->tenantAId);
        [$employmentId, $leaveTypeId] = $this->createEmploymentAndLeaveType();

        $this->expectException(QueryException::class);

        LeaveEntitlement::create([
            'employment_id' => $employmentId,
            'leave_type_id' => $leaveTypeId,
            'period_start' => '2026-12-31',
            'period_end' => '2026-01-01',
        ]);
    }

    public function test_check_constraint_rejects_unknown_status(): void
    {
        $this->activateTenantContext($this->tenantAId);
        [$employmentId, $leaveTypeId] = $this->createEmploymentAndLeaveType();

        $this->expectException(QueryException::class);

        LeaveEntitlement::create([
            'employment_id' => $employmentId,
            'leave_type_id' => $leaveTypeId,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'status' => 'UNKNOWN',
        ]);
    }

    public function test_composite_foreign_key_rejects_employment_from_another_tenant(): void
    {
        $this->activateTenantContext($this->tenantBId);
        [$employmentFromTenantB] = $this->createEmploymentAndLeaveType();

        $this->activateTenantContext($this->tenantAId);
        $leaveTypeId = $this->createLeaveType();

        $this->expectException(QueryException::class);

        LeaveEntitlement::create([
            'employment_id' => $employmentFromTenantB,
            'leave_type_id' => $leaveTypeId,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]);
    }

    private function activateTenantContext(string $tenantId): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($tenantId);

        app(TenantContextInterface::class)->setCurrentTenant($tenant);
    }

    private function createTenant(): string
    {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Leave Entitlement Tenant',
            'subdomain' => sprintf(
                'leave-entitlement-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createLeaveType(): string
    {
        return LeaveType::create([
            'code' => 'ANNUAL-'.Str::upper(Str::random(6)),
            'name' => 'Cuti Tahunan Uji',
            'category' => LeaveType::CATEGORY_LEAVE,
            'balance_mode' => LeaveType::BALANCE_MODE_BALANCE,
            'unit' => LeaveType::UNIT_DAY,
        ])->id;
    }

    /**
     * @return array{0: string, 1: string} [employmentId, leaveTypeId]
     */
    private function createEmploymentAndLeaveType(): array
    {
        $tenantId = (string) app(TenantContextInterface::class)->getCurrentTenantId();

        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();
        $employeeId = UuidV7::generate();
        $employmentId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Leave Entitlement Fixture Person',
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

        DB::table('employees')->insert([
            'id' => $employeeId,
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'nip' => null,
            'jabatan' => 'Guru',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employments')->insert([
            'id' => $employmentId,
            'tenant_id' => $tenantId,
            'employee_id' => $employeeId,
            'status' => 'ACTIVE',
            'start_date' => '2025-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$employmentId, $this->createLeaveType()];
    }
}
