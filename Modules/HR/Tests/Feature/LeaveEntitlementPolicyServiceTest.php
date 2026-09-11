<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Exceptions\LeaveLifecycleException;
use Modules\HR\Models\LeaveType;
use Modules\HR\Services\LeaveEntitlementPolicyService;
use Tests\TestCase;

final class LeaveEntitlementPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeaveEntitlementPolicyService $service;

    private string $tenantId;

    private string $leaveTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LeaveEntitlementPolicyService;
        $this->tenantId = $this->createTenant();
        $this->activateTenantContext($this->tenantId);
        $this->leaveTypeId = $this->createLeaveType();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_create_policy_succeeds_for_balance_backed_leave_type(): void
    {
        $policy = $this->service->createPolicy($this->tenantId, [
            'leave_type_id' => $this->leaveTypeId,
            'period_basis' => 'CALENDAR_YEAR',
            'grant_units' => 12,
            'effective_from' => '2026-01-01',
        ]);

        $this->assertSame(12.0, (float) $policy->grant_units);
        $this->assertSame(0, $policy->priority);
    }

    public function test_create_policy_rejects_non_balance_backed_leave_type(): void
    {
        $permitTypeId = LeaveType::create([
            'code' => 'PERMIT_NO_BALANCE',
            'name' => 'Izin Tanpa Saldo',
            'category' => LeaveType::CATEGORY_PERMIT,
            'balance_mode' => LeaveType::BALANCE_MODE_NONE,
            'unit' => LeaveType::UNIT_DAY,
        ])->id;

        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/not BALANCE-backed/');

        $this->service->createPolicy($this->tenantId, [
            'leave_type_id' => $permitTypeId,
            'period_basis' => 'CALENDAR_YEAR',
            'grant_units' => 12,
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_create_policy_rejects_unit_scope_without_organization(): void
    {
        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/requires organization_id/');

        $this->service->createPolicy($this->tenantId, [
            'leave_type_id' => $this->leaveTypeId,
            'organization_unit_id' => UuidV7::generate(),
            'period_basis' => 'CALENDAR_YEAR',
            'grant_units' => 12,
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_resolve_returns_tenant_wide_policy_when_only_candidate(): void
    {
        $employmentId = $this->createEmploymentWithPlacement();

        $this->service->createPolicy($this->tenantId, [
            'leave_type_id' => $this->leaveTypeId,
            'period_basis' => 'CALENDAR_YEAR',
            'grant_units' => 12,
            'effective_from' => '2026-01-01',
        ]);

        $resolved = $this->service->resolve(
            $this->tenantId,
            $this->leaveTypeId,
            $employmentId,
            '2026-06-01',
        );

        $this->assertSame(12.0, (float) $resolved->grant_units);
    }

    public function test_resolve_prefers_organization_scoped_over_tenant_wide(): void
    {
        [$employmentId, $organizationId] = $this->createEmploymentWithPlacementAndOrganization();

        $this->service->createPolicy($this->tenantId, [
            'leave_type_id' => $this->leaveTypeId,
            'period_basis' => 'CALENDAR_YEAR',
            'grant_units' => 12,
            'effective_from' => '2026-01-01',
        ]);
        $this->service->createPolicy($this->tenantId, [
            'leave_type_id' => $this->leaveTypeId,
            'organization_id' => $organizationId,
            'period_basis' => 'CALENDAR_YEAR',
            'grant_units' => 15,
            'effective_from' => '2026-01-01',
        ]);

        $resolved = $this->service->resolve(
            $this->tenantId,
            $this->leaveTypeId,
            $employmentId,
            '2026-06-01',
        );

        $this->assertSame(15.0, (float) $resolved->grant_units);
    }

    public function test_resolve_uses_priority_as_final_tiebreak(): void
    {
        $employmentId = $this->createEmploymentWithPlacement();

        $this->service->createPolicy($this->tenantId, [
            'leave_type_id' => $this->leaveTypeId,
            'period_basis' => 'CALENDAR_YEAR',
            'grant_units' => 10,
            'effective_from' => '2026-01-01',
            'priority' => 1,
        ]);
        $this->service->createPolicy($this->tenantId, [
            'leave_type_id' => $this->leaveTypeId,
            'period_basis' => 'CALENDAR_YEAR',
            'grant_units' => 20,
            'effective_from' => '2026-01-01',
            'priority' => 5,
        ]);

        $resolved = $this->service->resolve(
            $this->tenantId,
            $this->leaveTypeId,
            $employmentId,
            '2026-06-01',
        );

        $this->assertSame(20.0, (float) $resolved->grant_units);
    }

    public function test_resolve_throws_not_found_when_no_candidate(): void
    {
        $employmentId = $this->createEmploymentWithPlacement();

        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/LEAVE_ENTITLEMENT_POLICY_NOT_FOUND/');

        $this->service->resolve(
            $this->tenantId,
            $this->leaveTypeId,
            $employmentId,
            '2026-06-01',
        );
    }

    public function test_resolve_throws_ambiguous_on_unresolved_tie(): void
    {
        $employmentId = $this->createEmploymentWithPlacement();

        $this->service->createPolicy($this->tenantId, [
            'leave_type_id' => $this->leaveTypeId,
            'period_basis' => 'CALENDAR_YEAR',
            'grant_units' => 10,
            'effective_from' => '2026-01-01',
        ]);
        $this->service->createPolicy($this->tenantId, [
            'leave_type_id' => $this->leaveTypeId,
            'period_basis' => 'CALENDAR_YEAR',
            'grant_units' => 20,
            'effective_from' => '2026-01-01',
        ]);

        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/LEAVE_ENTITLEMENT_POLICY_AMBIGUOUS/');

        $this->service->resolve(
            $this->tenantId,
            $this->leaveTypeId,
            $employmentId,
            '2026-06-01',
        );
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
            'name' => 'Leave Entitlement Policy Tenant',
            'subdomain' => sprintf(
                'leave-policy-%s',
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
     * @return array{0: string, 1: string} [employmentId, organizationId]
     */
    private function createEmploymentWithPlacementAndOrganization(): array
    {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();
        $employeeId = UuidV7::generate();
        $employmentId = UuidV7::generate();
        $organizationId = UuidV7::generate();
        $assignmentId = UuidV7::generate();
        $placementId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Leave Policy Fixture Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employees')->insert([
            'id' => $employeeId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $membershipId,
            'nip' => null,
            'jabatan' => 'Guru',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employments')->insert([
            'id' => $employmentId,
            'tenant_id' => $this->tenantId,
            'employee_id' => $employeeId,
            'status' => 'ACTIVE',
            'start_date' => '2025-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'Leave Policy Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organizational_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $membershipId,
            'organization_id' => $organizationId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employment_placements')->insert([
            'id' => $placementId,
            'tenant_id' => $this->tenantId,
            'employment_id' => $employmentId,
            'organizational_assignment_id' => $assignmentId,
            'effective_from' => '2025-01-01',
            'effective_to' => null,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$employmentId, $organizationId];
    }

    private function createEmploymentWithPlacement(): string
    {
        return $this->createEmploymentWithPlacementAndOrganization()[0];
    }
}
