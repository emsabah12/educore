<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Identity\Models\User;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Exceptions\LeaveLifecycleException;
use Modules\HR\Models\LeaveApprovalPolicy;
use Modules\HR\Models\LeaveApprovalPolicyStep;
use Modules\HR\Models\LeaveBalanceLedger;
use Modules\HR\Models\LeaveEntitlement;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;
use Modules\HR\Services\LeaveApprovalPolicyService;
use Modules\HR\Services\LeaveApprovalService;
use Modules\HR\Services\LeaveBalanceService;
use Modules\HR\Services\LeaveRequestService;
use Tests\TestCase;

final class LeaveApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeaveApprovalPolicyService $approvalPolicyService;
    private LeaveRequestService $requestService;
    private LeaveApprovalService $approvalService;
    private LeaveBalanceService $balanceService;

    private string $tenantId;
    private string $employeeMembershipId;
    private string $approverUserId;
    private string $approverMembershipId;
    private string $employmentId;
    private string $leaveTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->approvalPolicyService = new LeaveApprovalPolicyService();
        $this->requestService = app(LeaveRequestService::class);
        $this->approvalService = app(LeaveApprovalService::class);
        $this->balanceService = new LeaveBalanceService();

        $this->tenantId = $this->createTenant();
        $this->activateTenantContext($this->tenantId);

        [$this->employmentId, $this->employeeMembershipId] = $this->createEmploymentWithPlacement();
        $this->leaveTypeId = $this->createLeaveType();

        [$this->approverUserId, $this->approverMembershipId] = $this->createApprover('hr.leave.approve');
    }

    protected function tearDown(): void
    {
        app(Request::class)->attributes->remove('authenticated_membership_id');
        app(TenantContextInterface::class)->clear();
        auth()->guard()->forgetUser();

        parent::tearDown();
    }

    public function test_approve_current_step_transitions_single_step_request_directly_to_approved(): void
    {
        $this->createTenantScopedPolicy();
        LeaveEntitlement::create([
            'employment_id' => $this->employmentId,
            'leave_type_id' => $this->leaveTypeId,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]);
        $entitlement = LeaveEntitlement::query()
            ->where('employment_id', $this->employmentId)
            ->where('leave_type_id', $this->leaveTypeId)
            ->firstOrFail();
        DB::table('leave_balance_ledger')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId,
            'entitlement_id' => $entitlement->id,
            'entry_type' => LeaveBalanceLedger::ENTRY_GRANT,
            'units_delta' => 12,
            'idempotency_key' => 'grant:' . $entitlement->id,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $request = $this->submitRequest();

        $this->authenticateApprover();

        $result = $this->approvalService->approveCurrentStep(
            $this->tenantId,
            $request->id,
            $this->approverMembershipId,
        );

        $this->assertSame(LeaveRequest::STATUS_APPROVED, $result->status);
    }

    public function test_approve_current_step_rejects_self_approval(): void
    {
        $this->createTenantScopedPolicy();
        $request = $this->submitRequest();

        $employeeUserId = $this->grantApprovalPermissionToExistingMembership(
            $this->employeeMembershipId,
            'hr.leave.approve',
        );

        $this->authenticateAsUser($employeeUserId);
        app(Request::class)->attributes->set('authenticated_membership_id', $this->employeeMembershipId);

        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/LEAVE_SELF_APPROVAL_FORBIDDEN/');

        $this->approvalService->approveCurrentStep(
            $this->tenantId,
            $request->id,
            $this->employeeMembershipId,
        );
    }

    public function test_approve_current_step_enforces_sequential_order(): void
    {
        $policy = $this->createTenantScopedPolicy();
        $this->approvalPolicyService->addStep(
            $this->tenantId,
            $policy->id,
            2,
            'hr.leave.approve.senior',
            LeaveApprovalPolicyStep::SCOPE_TENANT,
        );
        $request = $this->submitRequest();

        $this->authenticateApprover();

        // Approve step 1 dulu — sukses.
        $afterStepOne = $this->approvalService->approveCurrentStep(
            $this->tenantId,
            $request->id,
            $this->approverMembershipId,
        );

        // Masih ada step 2 (PENDING) -> request harus IN_REVIEW, BUKAN
        // langsung APPROVED walau approver yang sama punya kedua
        // permission tersebut.
        $this->assertSame(LeaveRequest::STATUS_IN_REVIEW, $afterStepOne->status);
    }

    public function test_reject_current_step_skips_later_steps(): void
    {
        $policy = $this->createTenantScopedPolicy();
        $this->approvalPolicyService->addStep(
            $this->tenantId,
            $policy->id,
            2,
            'hr.leave.approve.senior',
            LeaveApprovalPolicyStep::SCOPE_TENANT,
        );
        $request = $this->submitRequest();

        $this->authenticateApprover();

        $result = $this->approvalService->rejectCurrentStep(
            $this->tenantId,
            $request->id,
            $this->approverMembershipId,
            'Tidak memenuhi syarat.',
        );

        $this->assertSame(LeaveRequest::STATUS_REJECTED, $result->status);
        $this->assertSame(
            ['REJECTED', 'SKIPPED'],
            $result->approvalSteps->pluck('status')->all(),
        );
    }

    public function test_final_approval_consumes_balance_and_records_ledger_entry(): void
    {
        $this->createTenantScopedPolicy();
        $entitlement = LeaveEntitlement::create([
            'employment_id' => $this->employmentId,
            'leave_type_id' => $this->leaveTypeId,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]);
        DB::table('leave_balance_ledger')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId,
            'entitlement_id' => $entitlement->id,
            'entry_type' => LeaveBalanceLedger::ENTRY_GRANT,
            'units_delta' => 12,
            'idempotency_key' => 'grant:' . $entitlement->id,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $request = $this->submitRequest();
        $this->authenticateApprover();

        $this->approvalService->approveCurrentStep(
            $this->tenantId,
            $request->id,
            $this->approverMembershipId,
        );

        $this->assertSame(
            '10.00',
            $this->balanceService->balance($this->tenantId, $entitlement->id),
        );
    }

    public function test_final_approval_rejects_insufficient_balance(): void
    {
        $this->createTenantScopedPolicy();
        $entitlement = LeaveEntitlement::create([
            'employment_id' => $this->employmentId,
            'leave_type_id' => $this->leaveTypeId,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]);
        DB::table('leave_balance_ledger')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId,
            'entitlement_id' => $entitlement->id,
            'entry_type' => LeaveBalanceLedger::ENTRY_GRANT,
            'units_delta' => 1,
            'idempotency_key' => 'grant:' . $entitlement->id,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $request = $this->submitRequest();
        $this->authenticateApprover();

        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/LEAVE_INSUFFICIENT_BALANCE/');

        $this->approvalService->approveCurrentStep(
            $this->tenantId,
            $request->id,
            $this->approverMembershipId,
        );
    }

    public function test_final_approval_rejects_overlapping_approved_request(): void
    {
        $this->createTenantScopedPolicy();

        DB::table('leave_requests')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId,
            'employment_id' => $this->employmentId,
            'leave_type_id' => $this->leaveTypeId,
            'status' => LeaveRequest::STATUS_APPROVED,
            'starts_at' => '2026-06-01 00:00:00',
            'ends_at' => '2026-06-05 00:00:00',
            'request_timezone' => 'Asia/Jakarta',
            'requested_units' => 4,
            'unit' => LeaveType::UNIT_DAY,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // LeaveType di sini NONE (permit) supaya tidak perlu setup
        // entitlement — fokus murni ke exclusion constraint overlap.
        $noneTypeId = LeaveType::create([
            'code' => 'PERMIT-' . Str::upper(Str::random(6)),
            'name' => 'Izin Tanpa Saldo',
            'category' => LeaveType::CATEGORY_PERMIT,
            'balance_mode' => LeaveType::BALANCE_MODE_NONE,
            'unit' => LeaveType::UNIT_DAY,
        ])->id;

        $request = $this->requestService->createDraft(
            $this->tenantId,
            $this->employmentId,
            $noneTypeId,
            '2026-06-03 00:00:00',
            '2026-06-07 00:00:00',
            'Asia/Jakarta',
            '2',
        );
        $this->requestService->submit($this->tenantId, $request->id, $this->approverMembershipId);

        $this->authenticateApprover();

        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/LEAVE_REQUEST_OVERLAP/');

        $this->approvalService->approveCurrentStep(
            $this->tenantId,
            $request->id,
            $this->approverMembershipId,
        );
    }

    private function authenticateApprover(): void
    {
        $this->authenticateAsUser($this->approverUserId);
        app(Request::class)->attributes->set('authenticated_membership_id', $this->approverMembershipId);
    }

    private function submitRequest(): LeaveRequest
    {
        $request = $this->requestService->createDraft(
            $this->tenantId,
            $this->employmentId,
            $this->leaveTypeId,
            '2026-06-01 00:00:00',
            '2026-06-03 00:00:00',
            'Asia/Jakarta',
            '2',
        );

        return $this->requestService->submit($this->tenantId, $request->id, $this->approverMembershipId);
    }

    private function createTenantScopedPolicy(): LeaveApprovalPolicy
    {
        $policy = $this->approvalPolicyService->createPolicyVersion($this->tenantId, [
            'policy_code' => 'TENANT-STANDARD',
            'name' => 'Kebijakan Tenant',
            'decision_mode' => 'SEQUENTIAL',
            'effective_from' => '2026-01-01',
        ]);

        $this->approvalPolicyService->addStep(
            $this->tenantId,
            $policy->id,
            1,
            'hr.leave.approve',
            LeaveApprovalPolicyStep::SCOPE_TENANT,
        );

        return $policy;
    }

    private function authenticateAsUser(string $userId): void
    {
        $this->actingAs(User::query()->findOrFail($userId));
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
            'name' => 'Leave Approval Service Tenant',
            'subdomain' => sprintf(
                'leave-approval-svc-%s',
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
            'code' => 'ANNUAL-' . Str::upper(Str::random(6)),
            'name' => 'Cuti Tahunan Uji',
            'category' => LeaveType::CATEGORY_LEAVE,
            'balance_mode' => LeaveType::BALANCE_MODE_BALANCE,
            'unit' => LeaveType::UNIT_DAY,
        ])->id;
    }

    /**
     * @return array{0: string, 1: string} [employmentId, membershipId]
     */
    private function createEmploymentWithPlacement(): array
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
            'name' => 'Leave Approval Service Fixture Employee Person',
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
            'name' => 'Leave Approval Service Fixture Organization',
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

        return [$employmentId, $membershipId];
    }

    /**
     * @return array{0: string, 1: string} [userId, membershipId]
     */
    private function createApprover(string $permissionName): array
    {
        $personId = UuidV7::generate();
        $userId = UuidV7::generate();
        $membershipId = UuidV7::generate();
        $roleId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Leave Approval Service Fixture Approver Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $userId,
            'person_id' => $personId,
            'email' => sprintf('approver-%s@educore.test', Str::lower(Str::random(10))),
            'password' => bcrypt('irrelevant-password'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
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

        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'leave-approver-' . Str::lower(Str::random(6)),
            'display_name' => 'Leave Approver Test Role',
            'description' => 'Test-only role granting leave approval permission.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$permissionName, 'hr.leave.approve.senior'] as $permission) {
            $permissionId = DB::table('permissions')->where('name', $permission)->value('id');

            if ($permissionId === null) {
                $permissionId = UuidV7::generate();
                DB::table('permissions')->insert([
                    'id' => $permissionId,
                    'name' => $permission,
                    'display_name' => $permission,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }

        DB::table('membership_roles')->insertOrIgnore([
            'membership_id' => $membershipId,
            'role_id' => $roleId,
        ]);

        return [$userId, $membershipId];
    }

    /**
     * Membuat User account NYATA untuk person pemilik $membershipId yang
     * SUDAH ADA, lalu memberinya permission — dipakai supaya subjek cuti
     * sendiri bisa login sebagai dirinya sendiri (bukan orang lain yang
     * "meminjam" membership-nya) untuk skenario self-approval yang
     * realistis.
     */
    private function grantApprovalPermissionToExistingMembership(
        string $membershipId,
        string $permissionName,
    ): string {
        $personId = DB::table('memberships')->where('id', $membershipId)->value('person_id');
        $userId = UuidV7::generate();
        $roleId = UuidV7::generate();

        DB::table('users')->insert([
            'id' => $userId,
            'person_id' => $personId,
            'email' => sprintf('employee-%s@educore.test', Str::lower(Str::random(10))),
            'password' => bcrypt('irrelevant-password'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'self-approve-test-' . Str::lower(Str::random(6)),
            'display_name' => 'Self Approval Test Role',
            'description' => 'Test-only role granting leave approval permission to the leave subject.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')->where('name', $permissionName)->value('id');

        if ($permissionId === null) {
            $permissionId = UuidV7::generate();
            DB::table('permissions')->insert([
                'id' => $permissionId,
                'name' => $permissionName,
                'display_name' => $permissionName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('role_permissions')->insertOrIgnore([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);

        DB::table('membership_roles')->insertOrIgnore([
            'membership_id' => $membershipId,
            'role_id' => $roleId,
        ]);

        return $userId;
    }
}
