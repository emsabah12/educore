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
use Modules\HR\Models\Employment;
use Modules\HR\Models\LeaveApprovalPolicyStep;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;
use Modules\HR\Services\LeaveApprovalPolicyService;
use Modules\HR\Services\LeaveRequestService;
use Tests\TestCase;

final class LeaveRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeaveRequestService $service;
    private LeaveApprovalPolicyService $approvalPolicyService;
    private string $tenantId;
    private string $membershipId;
    private string $employmentId;
    private string $leaveTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->approvalPolicyService = new LeaveApprovalPolicyService();
        $this->service = new LeaveRequestService($this->approvalPolicyService);

        $this->tenantId = $this->createTenant();
        $this->activateTenantContext($this->tenantId);
        [$this->employmentId, $this->membershipId] = $this->createEmploymentWithPlacement();
        $this->leaveTypeId = $this->createLeaveType();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_create_draft_snapshots_unit_from_leave_type(): void
    {
        $request = $this->service->createDraft(
            $this->tenantId,
            $this->employmentId,
            $this->leaveTypeId,
            '2026-06-01 00:00:00',
            '2026-06-03 00:00:00',
            'Asia/Jakarta',
            '2',
        );

        $this->assertSame(LeaveType::UNIT_DAY, $request->unit);
        $this->assertSame(LeaveRequest::STATUS_DRAFT, $request->status);
    }

    public function test_submit_transitions_draft_to_submitted_and_snapshots_steps_in_order(): void
    {
        $this->createSequentialApprovalPolicy();
        $request = $this->createDraftRequest();

        $submitted = $this->service->submit($this->tenantId, $request->id, $this->membershipId);

        $this->assertSame(LeaveRequest::STATUS_SUBMITTED, $submitted->status);
        $this->assertNotNull($submitted->approval_policy_id);
        $this->assertNotNull($submitted->approval_context_placement_id);
        $this->assertSame(
            [1, 2],
            $submitted->approvalSteps->pluck('step_order')->all(),
        );
    }

    public function test_submit_rejects_when_employment_not_active(): void
    {
        $this->createSequentialApprovalPolicy();
        $request = $this->createDraftRequest();

        Employment::query()->where('id', $this->employmentId)->update([
            'status' => 'ENDED',
            'end_date' => '2026-01-01',
        ]);

        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/LEAVE_EMPLOYMENT_NOT_ACTIVE/');

        $this->service->submit($this->tenantId, $request->id, $this->membershipId);
    }

    public function test_submit_rejects_when_leave_type_inactive(): void
    {
        $this->createSequentialApprovalPolicy();
        $request = $this->createDraftRequest();

        LeaveType::query()->where('id', $this->leaveTypeId)->update(['is_active' => false]);

        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/LEAVE_TYPE_INACTIVE/');

        $this->service->submit($this->tenantId, $request->id, $this->membershipId);
    }

    public function test_submit_rejects_when_not_draft(): void
    {
        $this->createSequentialApprovalPolicy();
        $request = $this->createDraftRequest();
        $this->service->submit($this->tenantId, $request->id, $this->membershipId);

        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/cannot be submitted from status \[SUBMITTED\]/');

        $this->service->submit($this->tenantId, $request->id, $this->membershipId);
    }

    public function test_withdraw_transitions_submitted_to_withdrawn(): void
    {
        $this->createSequentialApprovalPolicy();
        $request = $this->createDraftRequest();
        $this->service->submit($this->tenantId, $request->id, $this->membershipId);

        $withdrawn = $this->service->withdraw($this->tenantId, $request->id);

        $this->assertSame(LeaveRequest::STATUS_WITHDRAWN, $withdrawn->status);
        $this->assertNotNull($withdrawn->withdrawn_at);
    }

    public function test_withdraw_rejects_from_approved(): void
    {
        $request = $this->createDraftRequest();
        LeaveRequest::query()->where('id', $request->id)->update(['status' => LeaveRequest::STATUS_APPROVED]);

        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/cannot be withdrawn from status \[APPROVED\]/');

        $this->service->withdraw($this->tenantId, $request->id);
    }

    public function test_get_history_returns_requests_ordered_by_most_recent(): void
    {
        $first = $this->createDraftRequest();
        sleep(1);
        $second = $this->service->createDraft(
            $this->tenantId,
            $this->employmentId,
            $this->leaveTypeId,
            '2026-07-01 00:00:00',
            '2026-07-02 00:00:00',
            'Asia/Jakarta',
            '1',
        );

        $history = $this->service->getHistory($this->tenantId, $this->employmentId);

        $this->assertSame($second->id, $history->first()->id);
        $this->assertSame($first->id, $history->last()->id);
    }

    private function createDraftRequest(): LeaveRequest
    {
        return $this->service->createDraft(
            $this->tenantId,
            $this->employmentId,
            $this->leaveTypeId,
            '2026-06-01 00:00:00',
            '2026-06-03 00:00:00',
            'Asia/Jakarta',
            '2',
        );
    }

    private function createSequentialApprovalPolicy(): void
    {
        $policy = $this->approvalPolicyService->createPolicyVersion($this->tenantId, [
            'policy_code' => 'STANDARD',
            'name' => 'Kebijakan Standar',
            'decision_mode' => 'SEQUENTIAL',
            'effective_from' => '2026-01-01',
        ]);

        $this->approvalPolicyService->addStep(
            $this->tenantId,
            $policy->id,
            2,
            'hr.leave.approve.senior',
            LeaveApprovalPolicyStep::SCOPE_ORGANIZATION,
        );
        $this->approvalPolicyService->addStep(
            $this->tenantId,
            $policy->id,
            1,
            'hr.leave.approve',
            LeaveApprovalPolicyStep::SCOPE_REQUEST_PLACEMENT,
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
            'name' => 'Leave Request Service Tenant',
            'subdomain' => sprintf(
                'leave-req-svc-%s',
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
            'name' => 'Leave Request Service Fixture Person',
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
            'name' => 'Leave Request Service Fixture Organization',
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
}
