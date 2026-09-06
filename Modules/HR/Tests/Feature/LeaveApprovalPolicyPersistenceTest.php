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
use Tests\TestCase;

final class LeaveApprovalPolicyPersistenceTest extends TestCase
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

    public function test_policy_can_be_created_with_default_priority_and_active_flag(): void
    {
        $this->activateTenantContext($this->tenantAId);

        $policy = LeaveApprovalPolicy::create([
            'policy_code' => 'STANDARD',
            'version_no' => 1,
            'name' => 'Kebijakan Approval Standar',
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-01-01',
        ]);

        $this->assertSame(0, $policy->priority);
        $this->assertTrue($policy->is_active);
    }

    public function test_database_rejects_duplicate_code_and_version(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $this->createPolicy('DUP', 1);

        $this->expectException(QueryException::class);

        $this->createPolicy('DUP', 1);
    }

    public function test_same_code_allows_incrementing_version(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $this->createPolicy('VERSIONED', 1);

        $second = $this->createPolicy('VERSIONED', 2);

        $this->assertSame(2, $second->version_no);
    }

    public function test_check_constraint_rejects_unknown_decision_mode(): void
    {
        $this->activateTenantContext($this->tenantAId);

        $this->expectException(QueryException::class);

        LeaveApprovalPolicy::create([
            'policy_code' => 'BAD_MODE',
            'version_no' => 1,
            'name' => 'Kebijakan Aneh',
            'decision_mode' => 'UNKNOWN',
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_check_constraint_rejects_zero_version(): void
    {
        $this->activateTenantContext($this->tenantAId);

        $this->expectException(QueryException::class);

        LeaveApprovalPolicy::create([
            'policy_code' => 'BAD_VERSION',
            'version_no' => 0,
            'name' => 'Kebijakan Aneh',
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_check_constraint_rejects_unit_scope_without_organization(): void
    {
        $this->activateTenantContext($this->tenantAId);

        $this->expectException(QueryException::class);

        LeaveApprovalPolicy::create([
            'policy_code' => 'BAD_UNIT',
            'version_no' => 1,
            'name' => 'Kebijakan Aneh',
            'organization_unit_id' => UuidV7::generate(),
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_composite_foreign_key_rejects_unit_from_different_organization(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $tenantId = $this->tenantAId;

        $organizationId = UuidV7::generate();
        $anotherOrganizationId = UuidV7::generate();
        $unitId = UuidV7::generate();

        DB::table('organizations')->insert([
            ['id' => $organizationId, 'tenant_id' => $tenantId, 'name' => 'Org A', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $anotherOrganizationId, 'tenant_id' => $tenantId, 'name' => 'Org B', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('organization_units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenantId,
            'organization_id' => $organizationId,
            'name' => 'Unit Milik Org A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        // Unit ini SAH ada, tapi diklaim milik Org B (padahal aslinya
        // milik Org A) — composite FK 3-kolom harus menolak ini.
        LeaveApprovalPolicy::create([
            'policy_code' => 'CROSS_ORG_UNIT',
            'version_no' => 1,
            'name' => 'Kebijakan Salah Org',
            'organization_id' => $anotherOrganizationId,
            'organization_unit_id' => $unitId,
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_step_can_be_created_with_default_independent_approver(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $policyId = $this->createPolicy('WITH_STEPS', 1)->id;

        $step = LeaveApprovalPolicyStep::create([
            'approval_policy_id' => $policyId,
            'step_order' => 1,
            'required_permission' => 'hr.leave.approve',
            'scope_strategy' => LeaveApprovalPolicyStep::SCOPE_REQUEST_PLACEMENT,
        ]);

        $this->assertTrue($step->independent_approver);
    }

    public function test_database_rejects_duplicate_step_order_for_same_policy(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $policyId = $this->createPolicy('DUP_STEP_ORDER', 1)->id;

        LeaveApprovalPolicyStep::create([
            'approval_policy_id' => $policyId,
            'step_order' => 1,
            'required_permission' => 'hr.leave.approve',
            'scope_strategy' => LeaveApprovalPolicyStep::SCOPE_REQUEST_PLACEMENT,
        ]);

        $this->expectException(QueryException::class);

        LeaveApprovalPolicyStep::create([
            'approval_policy_id' => $policyId,
            'step_order' => 1,
            'required_permission' => 'hr.leave.approve.senior',
            'scope_strategy' => LeaveApprovalPolicyStep::SCOPE_ORGANIZATION,
        ]);
    }

    public function test_check_constraint_rejects_unknown_scope_strategy(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $policyId = $this->createPolicy('BAD_SCOPE', 1)->id;

        $this->expectException(QueryException::class);

        LeaveApprovalPolicyStep::create([
            'approval_policy_id' => $policyId,
            'step_order' => 1,
            'required_permission' => 'hr.leave.approve',
            'scope_strategy' => 'UNKNOWN',
        ]);
    }

    public function test_steps_relation_orders_by_step_order(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $policy = $this->createPolicy('ORDERED_STEPS', 1);

        LeaveApprovalPolicyStep::create([
            'approval_policy_id' => $policy->id,
            'step_order' => 2,
            'required_permission' => 'hr.leave.approve.senior',
            'scope_strategy' => LeaveApprovalPolicyStep::SCOPE_ORGANIZATION,
        ]);
        LeaveApprovalPolicyStep::create([
            'approval_policy_id' => $policy->id,
            'step_order' => 1,
            'required_permission' => 'hr.leave.approve',
            'scope_strategy' => LeaveApprovalPolicyStep::SCOPE_REQUEST_PLACEMENT,
        ]);

        $this->assertSame(
            [1, 2],
            $policy->steps->pluck('step_order')->all(),
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
            'name' => 'Leave Approval Policy Tenant',
            'subdomain' => sprintf(
                'leave-approval-policy-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createPolicy(string $code, int $versionNo): LeaveApprovalPolicy
    {
        return LeaveApprovalPolicy::create([
            'policy_code' => $code,
            'version_no' => $versionNo,
            'name' => 'Kebijakan Uji ' . Str::random(6),
            'decision_mode' => LeaveApprovalPolicy::DECISION_MODE_SEQUENTIAL,
            'effective_from' => '2026-01-01',
        ]);
    }
}
