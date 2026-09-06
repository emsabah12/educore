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
use Modules\HR\Models\LeaveApprovalPolicy;
use Modules\HR\Models\LeaveApprovalPolicyStep;
use Modules\HR\Models\LeaveEntitlement;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveRequestApprovalStep;
use Modules\HR\Models\LeaveRequestEntitlementAllocation;
use Modules\HR\Models\LeaveType;
use Tests\TestCase;

final class LeaveRequestAllocationAndApprovalStepPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;
    private string $employmentId;
    private string $leaveTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->createTenant();
        $this->activateTenantContext($this->tenantId);
        [$this->employmentId, $this->leaveTypeId] = $this->createEmploymentAndLeaveType();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_allocation_can_be_created(): void
    {
        $requestId = $this->createRequest();
        $entitlementId = $this->createEntitlement();

        $allocation = LeaveRequestEntitlementAllocation::create([
            'leave_request_id' => $requestId,
            'entitlement_id' => $entitlementId,
            'allocated_units' => 2,
        ]);

        $this->assertTrue(Str::isUuid($allocation->id));
    }

    public function test_database_rejects_duplicate_allocation_for_same_request_and_entitlement(): void
    {
        $requestId = $this->createRequest();
        $entitlementId = $this->createEntitlement();

        LeaveRequestEntitlementAllocation::create([
            'leave_request_id' => $requestId,
            'entitlement_id' => $entitlementId,
            'allocated_units' => 1,
        ]);

        $this->expectException(QueryException::class);

        LeaveRequestEntitlementAllocation::create([
            'leave_request_id' => $requestId,
            'entitlement_id' => $entitlementId,
            'allocated_units' => 1,
        ]);
    }

    public function test_check_constraint_rejects_zero_allocated_units(): void
    {
        $requestId = $this->createRequest();
        $entitlementId = $this->createEntitlement();

        $this->expectException(QueryException::class);

        LeaveRequestEntitlementAllocation::create([
            'leave_request_id' => $requestId,
            'entitlement_id' => $entitlementId,
            'allocated_units' => 0,
        ]);
    }

    public function test_approval_step_can_be_created_with_default_pending_status(): void
    {
        $requestId = $this->createRequest();
        $policyStepId = $this->createApprovalPolicyStep();

        $step = LeaveRequestApprovalStep::create([
            'leave_request_id' => $requestId,
            'policy_step_id' => $policyStepId,
            'step_order' => 1,
            'required_permission' => 'hr.leave.approve',
            'scope_strategy' => LeaveApprovalPolicyStep::SCOPE_REQUEST_PLACEMENT,
            'independent_approver' => true,
        ]);

        $this->assertSame(LeaveRequestApprovalStep::STATUS_PENDING, $step->status);
    }

    public function test_database_rejects_duplicate_step_order_for_same_request(): void
    {
        $requestId = $this->createRequest();
        $policyStepId = $this->createApprovalPolicyStep();

        LeaveRequestApprovalStep::create([
            'leave_request_id' => $requestId,
            'policy_step_id' => $policyStepId,
            'step_order' => 1,
            'required_permission' => 'hr.leave.approve',
            'scope_strategy' => LeaveApprovalPolicyStep::SCOPE_REQUEST_PLACEMENT,
            'independent_approver' => true,
        ]);

        $this->expectException(QueryException::class);

        LeaveRequestApprovalStep::create([
            'leave_request_id' => $requestId,
            'policy_step_id' => $policyStepId,
            'step_order' => 1,
            'required_permission' => 'hr.leave.approve.senior',
            'scope_strategy' => LeaveApprovalPolicyStep::SCOPE_ORGANIZATION,
            'independent_approver' => true,
        ]);
    }

    public function test_check_constraint_rejects_unknown_approval_step_status(): void
    {
        $requestId = $this->createRequest();
        $policyStepId = $this->createApprovalPolicyStep();

        $this->expectException(QueryException::class);

        LeaveRequestApprovalStep::create([
            'leave_request_id' => $requestId,
            'policy_step_id' => $policyStepId,
            'step_order' => 1,
            'required_permission' => 'hr.leave.approve',
            'scope_strategy' => LeaveApprovalPolicyStep::SCOPE_REQUEST_PLACEMENT,
            'independent_approver' => true,
            'status' => 'UNKNOWN',
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
            'name' => 'Leave Request Allocation Tenant',
            'subdomain' => sprintf(
                'leave-req-alloc-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createRequest(): string
    {
        return LeaveRequest::create([
            'employment_id' => $this->employmentId,
            'leave_type_id' => $this->leaveTypeId,
            'starts_at' => '2026-06-01 00:00:00',
            'ends_at' => '2026-06-03 00:00:00',
            'request_timezone' => 'Asia/Jakarta',
            'requested_units' => 2,
            'unit' => LeaveType::UNIT_DAY,
        ])->id;
    }

    private function createEntitlement(): string
    {
        return LeaveEntitlement::create([
            'employment_id' => $this->employmentId,
            'leave_type_id' => $this->leaveTypeId,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ])->id;
    }

    private function createApprovalPolicyStep(): string
    {
        $policyId = LeaveApprovalPolicy::create([
            'policy_code' => 'FIXTURE-' . Str::upper(Str::random(6)),
            'version_no' => 1,
            'name' => 'Kebijakan Uji',
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-01-01',
        ])->id;

        return LeaveApprovalPolicyStep::create([
            'approval_policy_id' => $policyId,
            'step_order' => 1,
            'required_permission' => 'hr.leave.approve',
            'scope_strategy' => LeaveApprovalPolicyStep::SCOPE_REQUEST_PLACEMENT,
        ])->id;
    }

    /**
     * @return array{0: string, 1: string} [employmentId, leaveTypeId]
     */
    private function createEmploymentAndLeaveType(): array
    {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();
        $employeeId = UuidV7::generate();
        $employmentId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Leave Request Allocation Fixture Person',
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

        $leaveTypeId = LeaveType::create([
            'code' => 'ANNUAL-' . Str::upper(Str::random(6)),
            'name' => 'Cuti Tahunan Uji',
            'category' => LeaveType::CATEGORY_LEAVE,
            'balance_mode' => LeaveType::BALANCE_MODE_BALANCE,
            'unit' => LeaveType::UNIT_DAY,
        ])->id;

        return [$employmentId, $leaveTypeId];
    }
}
