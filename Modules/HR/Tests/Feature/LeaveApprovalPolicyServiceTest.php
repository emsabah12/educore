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
use Modules\HR\Models\LeaveApprovalPolicy;
use Modules\HR\Models\LeaveApprovalPolicyStep;
use Modules\HR\Models\LeaveType;
use Modules\HR\Services\LeaveApprovalPolicyService;
use Tests\TestCase;

final class LeaveApprovalPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeaveApprovalPolicyService $service;

    private string $tenantId;

    private string $leaveTypeId;

    private string $employmentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LeaveApprovalPolicyService;
        $this->tenantId = $this->createTenant();
        $this->activateTenantContext($this->tenantId);
        $this->leaveTypeId = $this->createLeaveType();
        $this->employmentId = $this->createEmployment();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_create_policy_version_starts_at_one(): void
    {
        $policy = $this->service->createPolicyVersion($this->tenantId, [
            'policy_code' => 'STANDARD',
            'name' => 'Kebijakan Standar',
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-01-01',
        ]);

        $this->assertSame(1, $policy->version_no);
    }

    public function test_create_policy_version_increments_for_same_code(): void
    {
        $this->service->createPolicyVersion($this->tenantId, [
            'policy_code' => 'VERSIONED',
            'name' => 'Versi Pertama',
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-01-01',
        ]);

        $second = $this->service->createPolicyVersion($this->tenantId, [
            'policy_code' => 'VERSIONED',
            'name' => 'Versi Kedua',
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-06-01',
        ]);

        $this->assertSame(2, $second->version_no);
    }

    public function test_create_policy_version_rejects_unit_without_organization(): void
    {
        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/requires organization_id/');

        $this->service->createPolicyVersion($this->tenantId, [
            'policy_code' => 'BAD_UNIT',
            'name' => 'Kebijakan Aneh',
            'organization_unit_id' => UuidV7::generate(),
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_add_step_creates_step_for_existing_policy(): void
    {
        $policy = $this->service->createPolicyVersion($this->tenantId, [
            'policy_code' => 'WITH_STEP',
            'name' => 'Kebijakan Dengan Langkah',
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-01-01',
        ]);

        $step = $this->service->addStep(
            $this->tenantId,
            $policy->id,
            1,
            'hr.leave.approve',
            LeaveApprovalPolicyStep::SCOPE_REQUEST_PLACEMENT,
        );

        $this->assertSame(1, $step->step_order);
        $this->assertTrue($step->independent_approver);
    }

    public function test_resolve_returns_generic_fallback_when_only_candidate(): void
    {
        $this->service->createPolicyVersion($this->tenantId, [
            'policy_code' => 'GENERIC',
            'name' => 'Kebijakan Umum',
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-01-01',
        ]);

        $resolved = $this->service->resolve(
            $this->tenantId,
            $this->leaveTypeId,
            $this->employmentId,
            '2026-06-01',
        );

        $this->assertSame('GENERIC', $resolved->policy_code);
    }

    public function test_resolve_prefers_organization_scoped_over_tenant_wide(): void
    {
        $organizationId = $this->attachOrganizationToEmployment($this->employmentId);

        $this->service->createPolicyVersion($this->tenantId, [
            'policy_code' => 'TENANT_WIDE',
            'name' => 'Kebijakan Tenant',
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-01-01',
        ]);
        $this->service->createPolicyVersion($this->tenantId, [
            'policy_code' => 'ORG_SCOPED',
            'name' => 'Kebijakan Organisasi',
            'organization_id' => $organizationId,
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-01-01',
        ]);

        $resolved = $this->service->resolve(
            $this->tenantId,
            $this->leaveTypeId,
            $this->employmentId,
            '2026-06-01',
        );

        $this->assertSame('ORG_SCOPED', $resolved->policy_code);
    }

    public function test_resolve_uses_priority_as_final_tiebreak(): void
    {
        $this->service->createPolicyVersion($this->tenantId, [
            'policy_code' => 'LOW_PRIORITY',
            'name' => 'Prioritas Rendah',
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-01-01',
            'priority' => 1,
        ]);
        $this->service->createPolicyVersion($this->tenantId, [
            'policy_code' => 'HIGH_PRIORITY',
            'name' => 'Prioritas Tinggi',
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-01-01',
            'priority' => 5,
        ]);

        $resolved = $this->service->resolve(
            $this->tenantId,
            $this->leaveTypeId,
            $this->employmentId,
            '2026-06-01',
        );

        $this->assertSame('HIGH_PRIORITY', $resolved->policy_code);
    }

    public function test_resolve_throws_not_found_when_no_candidate(): void
    {
        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/LEAVE_APPROVAL_POLICY_NOT_FOUND/');

        $this->service->resolve(
            $this->tenantId,
            $this->leaveTypeId,
            $this->employmentId,
            '2026-06-01',
        );
    }

    public function test_resolve_throws_ambiguous_when_leave_type_specific_ties_with_generic(): void
    {
        // Sengaja MEMBUKTIKAN keputusan desain: leave_type_id spesifik
        // vs generic fallback yang tied di scope+employment-filter+
        // priority TIDAK PERNAH otomatis dipilih salah satu — dokumen
        // hanya menyebut scope & employment-filter specificity sebagai
        // dimensi ranking eksplisit, jadi tie ini harus AMBIGUOUS,
        // memaksa admin membedakan lewat priority.
        $this->service->createPolicyVersion($this->tenantId, [
            'policy_code' => 'GENERIC_FALLBACK',
            'name' => 'Kebijakan Umum',
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-01-01',
        ]);
        $this->service->createPolicyVersion($this->tenantId, [
            'policy_code' => 'LEAVE_TYPE_SPECIFIC',
            'name' => 'Kebijakan Spesifik Leave Type',
            'leave_type_id' => $this->leaveTypeId,
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-01-01',
        ]);

        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/LEAVE_APPROVAL_POLICY_AMBIGUOUS/');

        $this->service->resolve(
            $this->tenantId,
            $this->leaveTypeId,
            $this->employmentId,
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
            'name' => 'Leave Approval Policy Service Tenant',
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
            'code' => 'ANNUAL-'.Str::upper(Str::random(6)),
            'name' => 'Cuti Tahunan Uji',
            'category' => LeaveType::CATEGORY_LEAVE,
            'balance_mode' => LeaveType::BALANCE_MODE_BALANCE,
            'unit' => LeaveType::UNIT_DAY,
        ])->id;
    }

    private function createEmployment(): string
    {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();
        $employeeId = UuidV7::generate();
        $employmentId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Leave Approval Policy Service Fixture Person',
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

        return $employmentId;
    }

    private function attachOrganizationToEmployment(string $employmentId): string
    {
        $membershipId = DB::table('employments')
            ->join('employees', 'employees.id', '=', 'employments.employee_id')
            ->where('employments.id', $employmentId)
            ->value('employees.membership_id');

        $organizationId = UuidV7::generate();
        $assignmentId = UuidV7::generate();
        $placementId = UuidV7::generate();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'Leave Approval Policy Service Fixture Organization',
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

        return $organizationId;
    }
}
