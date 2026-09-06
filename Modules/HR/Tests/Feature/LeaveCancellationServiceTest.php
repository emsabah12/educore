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
use Modules\HR\Models\LeaveApprovalPolicyStep;
use Modules\HR\Models\LeaveBalanceLedger;
use Modules\HR\Models\LeaveEntitlement;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;
use Modules\HR\Services\LeaveApprovalPolicyService;
use Modules\HR\Services\LeaveApprovalService;
use Modules\HR\Services\LeaveBalanceService;
use Modules\HR\Services\LeaveCancellationService;
use Modules\HR\Services\LeaveRequestService;
use Tests\TestCase;

final class LeaveCancellationServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeaveApprovalPolicyService $approvalPolicyService;
    private LeaveRequestService $requestService;
    private LeaveApprovalService $approvalService;
    private LeaveCancellationService $cancellationService;
    private LeaveBalanceService $balanceService;

    private string $tenantId;
    private string $employmentId;
    private string $leaveTypeId;
    private string $actorUserId;
    private string $actorMembershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->approvalPolicyService = new LeaveApprovalPolicyService();
        $this->requestService = app(LeaveRequestService::class);
        $this->approvalService = app(LeaveApprovalService::class);
        $this->cancellationService = app(LeaveCancellationService::class);
        $this->balanceService = new LeaveBalanceService();

        $this->tenantId = $this->createTenant();
        $this->activateTenantContext($this->tenantId);

        [$this->employmentId] = $this->createEmploymentWithPlacement();
        $this->leaveTypeId = $this->createLeaveType();

        [$this->actorUserId, $this->actorMembershipId] = $this->createActor([
            'hr.leave.approve',
            'hr.leave.cancel',
        ]);
    }

    protected function tearDown(): void
    {
        app(Request::class)->attributes->remove('authenticated_membership_id');
        app(TenantContextInterface::class)->clear();
        auth()->guard()->forgetUser();

        parent::tearDown();
    }

    public function test_cancel_approved_transitions_permit_request_to_cancelled(): void
    {
        $noneTypeId = $this->createNoneLeaveType();
        $request = $this->approveRequest($noneTypeId);

        $this->authenticateActor();

        $result = $this->cancellationService->cancelApproved(
            $this->tenantId,
            $request->id,
            $this->actorMembershipId,
            'Rencana berubah.',
        );

        $this->assertSame(LeaveRequest::STATUS_CANCELLED, $result->status);
        $this->assertNotNull($result->cancelled_at);
    }

    public function test_cancel_approved_restores_balance_for_balance_backed_request(): void
    {
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

        $request = $this->approveRequest($this->leaveTypeId);

        // Setelah approval: 12 - 2 = 10.
        $this->assertSame('10.00', $this->balanceService->balance($this->tenantId, $entitlement->id));

        $this->authenticateActor();

        $this->cancellationService->cancelApproved(
            $this->tenantId,
            $request->id,
            $this->actorMembershipId,
        );

        // Setelah cancel: 10 + 2 = 12 (kembali penuh).
        $this->assertSame('12.00', $this->balanceService->balance($this->tenantId, $entitlement->id));
    }

    public function test_cancel_approved_rejects_when_not_approved(): void
    {
        $noneTypeId = $this->createNoneLeaveType();
        $request = $this->requestService->createDraft(
            $this->tenantId,
            $this->employmentId,
            $noneTypeId,
            '2026-06-01 00:00:00',
            '2026-06-03 00:00:00',
            'Asia/Jakarta',
            '2',
        );

        $this->authenticateActor();

        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/LEAVE_CANCELLATION_NOT_ALLOWED/');

        $this->cancellationService->cancelApproved(
            $this->tenantId,
            $request->id,
            $this->actorMembershipId,
        );
    }

    public function test_cancel_approved_rejects_without_permission(): void
    {
        $noneTypeId = $this->createNoneLeaveType();
        $request = $this->approveRequest($noneTypeId);

        [$unauthorizedUserId, $unauthorizedMembershipId] = $this->createActor(['hr.leave.approve']);
        $this->actingAs(User::query()->findOrFail($unauthorizedUserId));
        app(Request::class)->attributes->set('authenticated_membership_id', $unauthorizedMembershipId);

        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/LEAVE_CANCELLATION_NOT_ALLOWED/');

        $this->cancellationService->cancelApproved(
            $this->tenantId,
            $request->id,
            $unauthorizedMembershipId,
        );
    }

    private function authenticateActor(): void
    {
        $this->actingAs(User::query()->findOrFail($this->actorUserId));
        app(Request::class)->attributes->set('authenticated_membership_id', $this->actorMembershipId);
    }

    private function approveRequest(string $leaveTypeId): LeaveRequest
    {
        $policy = $this->approvalPolicyService->createPolicyVersion($this->tenantId, [
            'policy_code' => 'CANCEL-TEST-' . Str::upper(Str::random(6)),
            'name' => 'Kebijakan Uji Cancel',
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

        $request = $this->requestService->createDraft(
            $this->tenantId,
            $this->employmentId,
            $leaveTypeId,
            '2026-06-01 00:00:00',
            '2026-06-03 00:00:00',
            'Asia/Jakarta',
            '2',
        );
        $submitted = $this->requestService->submit($this->tenantId, $request->id, $this->actorMembershipId);

        $this->authenticateActor();

        return $this->approvalService->approveCurrentStep(
            $this->tenantId,
            $submitted->id,
            $this->actorMembershipId,
        );
    }

    private function createNoneLeaveType(): string
    {
        return LeaveType::create([
            'code' => 'PERMIT-' . Str::upper(Str::random(6)),
            'name' => 'Izin Tanpa Saldo',
            'category' => LeaveType::CATEGORY_PERMIT,
            'balance_mode' => LeaveType::BALANCE_MODE_NONE,
            'unit' => LeaveType::UNIT_DAY,
        ])->id;
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
            'name' => 'Leave Cancellation Service Tenant',
            'subdomain' => sprintf(
                'leave-cancel-svc-%s',
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
            'name' => 'Leave Cancellation Service Fixture Employee',
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
            'name' => 'Leave Cancellation Service Fixture Organization',
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
     * @param list<string> $permissions
     *
     * @return array{0: string, 1: string} [userId, membershipId]
     */
    private function createActor(array $permissions): array
    {
        $personId = UuidV7::generate();
        $userId = UuidV7::generate();
        $membershipId = UuidV7::generate();
        $roleId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Leave Cancellation Service Fixture Actor',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $userId,
            'person_id' => $personId,
            'email' => sprintf('actor-%s@educore.test', Str::lower(Str::random(10))),
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
            'name' => 'leave-cancel-test-' . Str::lower(Str::random(6)),
            'display_name' => 'Leave Cancellation Test Role',
            'description' => 'Test-only role.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($permissions as $permission) {
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
}
