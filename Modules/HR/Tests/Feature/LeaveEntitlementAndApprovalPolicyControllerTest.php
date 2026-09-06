<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Database\Seeders\HrAuthorizationCatalogSeeder;
use Modules\HR\Models\LeaveApprovalPolicyStep;
use Modules\HR\Models\LeaveType;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class LeaveEntitlementAndApprovalPolicyControllerTest extends TestCase
{
    use RefreshDatabase;
    use GrantsAuthorizationRole;

    private string $tenantId;
    private string $operatorUserId;
    private string $operatorMembershipId;
    private string $employeeId;
    private string $employmentId;
    private string $leaveTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HrAuthorizationCatalogSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();

        $this->createTenantFixture();
        $this->activateTenantContext($this->tenantId);
        $this->createOperatorFixture();
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        [$this->employeeId, $this->employmentId] = $this->createEmploymentFixture();
        $this->leaveTypeId = $this->createLeaveTypeFixture();
    }

    private function activateTenantContext(string $tenantId): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($tenantId);

        app(TenantContextInterface::class)->setCurrentTenant($tenant);
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_generate_entitlement_creates_record_with_initial_grant(): void
    {
        $this->createEntitlementPolicyFixture();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.employments.leave-entitlements.generate', ['employmentId' => $this->employmentId], false),
                [
                    'leave_type_id' => $this->leaveTypeId,
                    'period_start' => '2026-01-01',
                    'period_end' => '2026-12-31',
                ],
            );

        $response->assertCreated();
    }

    public function test_employee_leave_balances_lists_entitlement_with_computed_balance(): void
    {
        $this->createEntitlementPolicyFixture();

        $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.employments.leave-entitlements.generate', ['employmentId' => $this->employmentId], false),
                [
                    'leave_type_id' => $this->leaveTypeId,
                    'period_start' => '2026-01-01',
                    'period_end' => '2026-12-31',
                ],
            )->assertCreated();

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route('api.v1.hr.employees.leave-balances.index', ['employeeId' => $this->employeeId], false),
            );

        $response->assertOk();
        $this->assertSame('12.00', $response->json('data.0.balance'));
    }

    public function test_adjust_entitlement_increases_balance(): void
    {
        $this->createEntitlementPolicyFixture();

        $generateResponse = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.employments.leave-entitlements.generate', ['employmentId' => $this->employmentId], false),
                [
                    'leave_type_id' => $this->leaveTypeId,
                    'period_start' => '2026-01-01',
                    'period_end' => '2026-12-31',
                ],
            );
        $entitlementId = $generateResponse->json('data.id');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-entitlements.adjustments.store', ['entitlementId' => $entitlementId], false),
                [
                    'units_delta' => 2,
                    'reason' => 'Koreksi administratif.',
                    'idempotency_key' => 'http-test-adjust-1',
                ],
            );

        $response
            ->assertCreated()
            ->assertJsonPath('data.balance', '14.00');
    }

    public function test_adjust_entitlement_rejects_duplicate_idempotency_key(): void
    {
        $this->createEntitlementPolicyFixture();

        $generateResponse = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.employments.leave-entitlements.generate', ['employmentId' => $this->employmentId], false),
                [
                    'leave_type_id' => $this->leaveTypeId,
                    'period_start' => '2026-01-01',
                    'period_end' => '2026-12-31',
                ],
            );
        $entitlementId = $generateResponse->json('data.id');

        $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-entitlements.adjustments.store', ['entitlementId' => $entitlementId], false),
                ['units_delta' => 1, 'idempotency_key' => 'dup-key'],
            )->assertCreated();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-entitlements.adjustments.store', ['entitlementId' => $entitlementId], false),
                ['units_delta' => 1, 'idempotency_key' => 'dup-key'],
            );

        $response->assertConflict();
    }

    public function test_store_approval_policy_creates_policy_with_nested_steps(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-approval-policies.store', [], false),
                [
                    'policy_code' => 'STD-HTTP',
                    'name' => 'Kebijakan Standar HTTP',
                    'decision_mode' => 'SEQUENTIAL',
                    'effective_from' => '2026-01-01',
                    'steps' => [
                        [
                            'step_order' => 1,
                            'required_permission' => 'hr.leave.approve',
                            'scope_strategy' => LeaveApprovalPolicyStep::SCOPE_TENANT,
                        ],
                    ],
                ],
            );

        $response
            ->assertCreated()
            ->assertJsonPath('data.version_no', 1)
            ->assertJsonCount(1, 'data.steps');
    }

    public function test_store_approval_policy_is_forbidden_without_permission(): void
    {
        [, $unauthorizedMembershipId] = $this->createUnauthorizedActor();

        $response = $this
            ->withToken(
                app(TokenManagerInterface::class)->issueToken(
                    $this->operatorUserId,
                    $this->tenantId,
                    ['membership_id' => $unauthorizedMembershipId],
                ),
            )
            ->postJson(
                route('api.v1.hr.leave-approval-policies.store', [], false),
                [
                    'policy_code' => 'STD-HTTP-2',
                    'name' => 'Kebijakan Standar HTTP',
                    'decision_mode' => 'SEQUENTIAL',
                    'effective_from' => '2026-01-01',
                ],
            );

        $response->assertForbidden();
    }

    public function test_deactivate_approval_policy_sets_inactive(): void
    {
        $storeResponse = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-approval-policies.store', [], false),
                [
                    'policy_code' => 'DEACTIVATE-HTTP',
                    'name' => 'Kebijakan Nonaktif HTTP',
                    'decision_mode' => 'AUTO',
                    'effective_from' => '2026-01-01',
                ],
            );
        $policyId = $storeResponse->json('data.id');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-approval-policies.deactivate', ['approvalPolicyId' => $policyId], false),
            );

        $response
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    private function createEntitlementPolicyFixture(): void
    {
        $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-entitlement-policies.store', [], false),
                [
                    'leave_type_id' => $this->leaveTypeId,
                    'period_basis' => 'CALENDAR_YEAR',
                    'grant_units' => 12,
                    'effective_from' => '2026-01-01',
                ],
            )->assertCreated();
    }

    /**
     * Membership TANPA role apa pun — dipakai untuk skenario "forbidden".
     *
     * @return array{0: string, 1: string} [userId, membershipId]
     */
    private function createUnauthorizedActor(): array
    {
        $personId = UuidV7::generate();
        $userId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Unauthorized Actor',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $userId,
            'person_id' => $personId,
            'email' => sprintf('unauthorized-%s@educore.test', Str::lower(Str::random(10))),
            'password' => 'not-used-by-token-test',
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

        return [$userId, $membershipId];
    }

    private function createLeaveTypeFixture(): string
    {
        return LeaveType::create([
            'code' => 'ANNUAL-' . Str::upper(Str::random(6)),
            'name' => 'Cuti Tahunan Uji HTTP',
            'category' => LeaveType::CATEGORY_LEAVE,
            'balance_mode' => LeaveType::BALANCE_MODE_BALANCE,
            'unit' => LeaveType::UNIT_DAY,
        ])->id;
    }

    /**
     * @return array{0: string, 1: string} [employeeId, employmentId]
     */
    private function createEmploymentFixture(): array
    {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();
        $employeeId = UuidV7::generate();
        $employmentId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Leave Entitlement HTTP Fixture Employee',
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

        return [$employeeId, $employmentId];
    }

    private function createTenantFixture(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Leave Entitlement HTTP Tenant',
            'subdomain' => sprintf(
                'leave-entitlement-http-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOperatorFixture(): void
    {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Leave Entitlement HTTP Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => sprintf(
                'leave-entitlement-operator-%s@educore.test',
                Str::lower(Str::random(10)),
            ),
            'password' => 'not-used-by-token-test',
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $this->operatorMembershipId,
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function issueToken(): string
    {
        return app(TokenManagerInterface::class)
            ->issueToken(
                $this->operatorUserId,
                $this->tenantId,
                ['membership_id' => $this->operatorMembershipId],
            );
    }
}
