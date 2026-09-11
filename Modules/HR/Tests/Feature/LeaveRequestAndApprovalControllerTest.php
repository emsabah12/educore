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
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class LeaveRequestAndApprovalControllerTest extends TestCase
{
    use GrantsAuthorizationRole;
    use RefreshDatabase;

    private string $tenantId;

    private string $operatorUserId;

    private string $operatorMembershipId;

    private string $employmentId;

    private string $noneTypeId;

    private string $balanceTypeId;

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
        // hr-officer mencakup hr.leave.manage/read/approve/cancel —
        // operator yang sama dipakai sebagai "petugas HR" yang membuat
        // draft ATAS NAMA karyawan sekaligus sebagai approver-nya.
        // Ini bukan self-approval karena employment fixture di bawah
        // adalah PERSON/MEMBERSHIP TERPISAH dari operator.
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $this->employmentId = $this->createEmploymentFixture();
        $this->noneTypeId = $this->createLeaveTypeFixture(LeaveType::BALANCE_MODE_NONE);
        $this->balanceTypeId = $this->createLeaveTypeFixture(LeaveType::BALANCE_MODE_BALANCE);
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_store_creates_draft_leave_request(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.hr.leave-requests.store', [], false), $this->requestPayload($this->noneTypeId));

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_DRAFT);
    }

    public function test_update_draft_changes_reason(): void
    {
        $requestId = $this->createDraftViaApi($this->noneTypeId);

        $response = $this
            ->withToken($this->issueToken())
            ->patchJson(
                route('api.v1.hr.leave-requests.update', ['leaveRequestId' => $requestId], false),
                ['reason' => 'Alasan diperbarui.'],
            );

        $response
            ->assertOk()
            ->assertJsonPath('data.reason', 'Alasan diperbarui.');
    }

    public function test_update_ignores_client_supplied_status(): void
    {
        $requestId = $this->createDraftViaApi($this->noneTypeId);

        $response = $this
            ->withToken($this->issueToken())
            ->patchJson(
                route('api.v1.hr.leave-requests.update', ['leaveRequestId' => $requestId], false),
                ['status' => 'APPROVED', 'reason' => 'Tetap draft.'],
            );

        $response
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_DRAFT);
    }

    public function test_submit_finalizes_immediately_for_auto_policy(): void
    {
        $this->createAutoPolicyFixture();
        $requestId = $this->createDraftViaApi($this->noneTypeId);

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.hr.leave-requests.submit', ['leaveRequestId' => $requestId], false));

        $response
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_APPROVED);
    }

    public function test_withdraw_transitions_submitted_to_withdrawn(): void
    {
        $this->createSequentialPolicyFixture();
        $requestId = $this->createDraftViaApi($this->noneTypeId);

        $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.hr.leave-requests.submit', ['leaveRequestId' => $requestId], false))
            ->assertOk();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.hr.leave-requests.withdraw', ['leaveRequestId' => $requestId], false));

        $response
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_WITHDRAWN);
    }

    public function test_pending_lists_actionable_step_after_submit(): void
    {
        $this->createSequentialPolicyFixture();
        $requestId = $this->createDraftViaApi($this->noneTypeId);

        $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.hr.leave-requests.submit', ['leaveRequestId' => $requestId], false))
            ->assertOk();

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(route('api.v1.hr.leave-approvals.pending', [], false));

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_approve_transitions_single_step_request_to_approved(): void
    {
        $this->createSequentialPolicyFixture();
        $requestId = $this->createDraftViaApi($this->noneTypeId);

        $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.hr.leave-requests.submit', ['leaveRequestId' => $requestId], false))
            ->assertOk();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.hr.leave-requests.approve', ['leaveRequestId' => $requestId], false));

        $response
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_APPROVED);
    }

    public function test_reject_transitions_request_to_rejected(): void
    {
        $this->createSequentialPolicyFixture();
        $requestId = $this->createDraftViaApi($this->noneTypeId);

        $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.hr.leave-requests.submit', ['leaveRequestId' => $requestId], false))
            ->assertOk();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-requests.reject', ['leaveRequestId' => $requestId], false),
                ['note' => 'Tidak memenuhi syarat.'],
            );

        $response
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_REJECTED);
    }

    public function test_cancel_transitions_approved_request_to_cancelled(): void
    {
        $this->createSequentialPolicyFixture();
        $requestId = $this->createDraftViaApi($this->noneTypeId);

        $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.hr.leave-requests.submit', ['leaveRequestId' => $requestId], false))
            ->assertOk();

        $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.hr.leave-requests.approve', ['leaveRequestId' => $requestId], false))
            ->assertOk();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-requests.cancel', ['leaveRequestId' => $requestId], false),
                ['note' => 'Rencana berubah.'],
            );

        $response
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_CANCELLED);
    }

    /**
     * @return array{
     *     employment_id: string,
     *     leave_type_id: string,
     *     starts_at: string,
     *     ends_at: string,
     *     request_timezone: string,
     *     requested_units: string,
     * }
     */
    private function requestPayload(string $leaveTypeId): array
    {
        return [
            'employment_id' => $this->employmentId,
            'leave_type_id' => $leaveTypeId,
            'starts_at' => '2026-06-01 00:00:00',
            'ends_at' => '2026-06-03 00:00:00',
            'request_timezone' => 'Asia/Jakarta',
            'requested_units' => '2',
        ];
    }

    private function createDraftViaApi(string $leaveTypeId): string
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(route('api.v1.hr.leave-requests.store', [], false), $this->requestPayload($leaveTypeId));

        return $response->json('data.id');
    }

    private function createSequentialPolicyFixture(): void
    {
        $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-approval-policies.store', [], false),
                [
                    'policy_code' => 'HTTP-SEQ',
                    'name' => 'Kebijakan Sequential HTTP',
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
            )->assertCreated();
    }

    private function createAutoPolicyFixture(): void
    {
        $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-approval-policies.store', [], false),
                [
                    'policy_code' => 'HTTP-AUTO',
                    'name' => 'Kebijakan Auto HTTP',
                    'decision_mode' => 'AUTO',
                    'effective_from' => '2026-01-01',
                ],
            )->assertCreated();
    }

    private function createLeaveTypeFixture(string $balanceMode): string
    {
        return LeaveType::create([
            'code' => 'HTTP-'.$balanceMode.'-'.Str::upper(Str::random(6)),
            'name' => 'Tipe Uji HTTP '.$balanceMode,
            'category' => $balanceMode === LeaveType::BALANCE_MODE_NONE
                ? LeaveType::CATEGORY_PERMIT
                : LeaveType::CATEGORY_LEAVE,
            'balance_mode' => $balanceMode,
            'unit' => LeaveType::UNIT_DAY,
        ])->id;
    }

    private function createEmploymentFixture(): string
    {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();
        $employeeId = UuidV7::generate();
        $employmentId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Leave Request HTTP Fixture Employee',
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

    private function activateTenantContext(string $tenantId): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($tenantId);

        app(TenantContextInterface::class)->setCurrentTenant($tenant);
    }

    private function createTenantFixture(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Leave Request HTTP Tenant',
            'subdomain' => sprintf('leave-request-http-%s', Str::lower(Str::random(12))),
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
            'name' => 'Leave Request HTTP Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => sprintf('leave-request-operator-%s@educore.test', Str::lower(Str::random(10))),
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
