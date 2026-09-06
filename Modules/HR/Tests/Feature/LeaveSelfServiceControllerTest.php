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
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;
use Tests\TestCase;

final class LeaveSelfServiceControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;
    private string $leaveTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HrAuthorizationCatalogSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->createTenantFixture();
        $this->activateTenantContext($this->tenantId);

        $this->leaveTypeId = LeaveType::create([
            'code' => 'SELF-' . Str::upper(Str::random(6)),
            'name' => 'Izin Uji Self-Service',
            'category' => LeaveType::CATEGORY_PERMIT,
            'balance_mode' => LeaveType::BALANCE_MODE_NONE,
            'unit' => LeaveType::UNIT_DAY,
        ])->id;
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_store_creates_own_draft_without_client_supplied_employment(): void
    {
        [$userId, $membershipId] = $this->createEmployeeActor();

        $response = $this
            ->withToken($this->issueToken($userId, $membershipId))
            ->postJson(route('api.v1.hr.self.leave-requests.store', [], false), $this->requestPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_DRAFT);
    }

    public function test_balances_returns_own_entitlements_only(): void
    {
        [$userId, $membershipId] = $this->createEmployeeActor();

        $response = $this
            ->withToken($this->issueToken($userId, $membershipId))
            ->getJson(route('api.v1.hr.self.leave-balances.index', [], false));

        $response->assertOk();
    }

    public function test_submit_transitions_own_draft(): void
    {
        // createEmployeeActor() sudah memberi hr.leave.self.* DAN
        // hr.leave.approve — cukup untuk skenario AUTO/SEQUENTIAL di
        // bawah tanpa perlu grant tambahan.
        [$userId, $membershipId] = $this->createEmployeeActor();
        $this->createAutoPolicyFixture();

        $token = $this->issueToken($userId, $membershipId);

        $storeResponse = $this
            ->withToken($token)
            ->postJson(route('api.v1.hr.self.leave-requests.store', [], false), $this->requestPayload());
        $requestId = $storeResponse->json('data.id');

        $response = $this
            ->withToken($token)
            ->postJson(route('api.v1.hr.self.leave-requests.submit', ['leaveRequestId' => $requestId], false));

        $response
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_APPROVED);
    }

    public function test_withdraw_transitions_own_submitted_request(): void
    {
        [$userId, $membershipId] = $this->createEmployeeActor();
        $this->createSequentialPolicyFixture();

        $token = $this->issueToken($userId, $membershipId);

        $storeResponse = $this
            ->withToken($token)
            ->postJson(route('api.v1.hr.self.leave-requests.store', [], false), $this->requestPayload());
        $requestId = $storeResponse->json('data.id');

        $this
            ->withToken($token)
            ->postJson(route('api.v1.hr.self.leave-requests.submit', ['leaveRequestId' => $requestId], false))
            ->assertOk();

        $response = $this
            ->withToken($token)
            ->postJson(route('api.v1.hr.self.leave-requests.withdraw', ['leaveRequestId' => $requestId], false));

        $response
            ->assertOk()
            ->assertJsonPath('data.status', LeaveRequest::STATUS_WITHDRAWN);
    }

    public function test_show_returns_not_found_for_another_employees_request(): void
    {
        [$ownerUserId, $ownerMembershipId] = $this->createEmployeeActor();
        [$viewerUserId, $viewerMembershipId] = $this->createEmployeeActor();

        $storeResponse = $this
            ->withToken($this->issueToken($ownerUserId, $ownerMembershipId))
            ->postJson(route('api.v1.hr.self.leave-requests.store', [], false), $this->requestPayload());
        $requestId = $storeResponse->json('data.id');

        $response = $this
            ->withToken($this->issueToken($viewerUserId, $viewerMembershipId))
            ->getJson(route('api.v1.hr.self.leave-requests.show', ['leaveRequestId' => $requestId], false));

        $response->assertNotFound();
    }

    /**
     * @return array{
     *     leave_type_id: string,
     *     starts_at: string,
     *     ends_at: string,
     *     request_timezone: string,
     *     requested_units: string,
     * }
     */
    private function requestPayload(): array
    {
        return [
            'leave_type_id' => $this->leaveTypeId,
            'starts_at' => '2026-06-01 00:00:00',
            'ends_at' => '2026-06-03 00:00:00',
            'request_timezone' => 'Asia/Jakarta',
            'requested_units' => '2',
        ];
    }

    private function createAutoPolicyFixture(): void
    {
        DB::table('leave_approval_policies')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId,
            'policy_code' => 'SELF-AUTO-' . Str::upper(Str::random(6)),
            'version_no' => 1,
            'name' => 'Kebijakan Auto Self-Service',
            'decision_mode' => 'AUTO',
            'priority' => 0,
            'is_active' => true,
            'effective_from' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSequentialPolicyFixture(): void
    {
        $policyId = UuidV7::generate();

        DB::table('leave_approval_policies')->insert([
            'id' => $policyId,
            'tenant_id' => $this->tenantId,
            'policy_code' => 'SELF-SEQ-' . Str::upper(Str::random(6)),
            'version_no' => 1,
            'name' => 'Kebijakan Sequential Self-Service',
            'decision_mode' => 'SEQUENTIAL',
            'priority' => 0,
            'is_active' => true,
            'effective_from' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('leave_approval_policy_steps')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId,
            'approval_policy_id' => $policyId,
            'step_order' => 1,
            'required_permission' => 'hr.leave.approve',
            'scope_strategy' => 'TENANT',
            'independent_approver' => true,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{0: string, 1: string} [userId, membershipId]
     */
    private function createEmployeeActor(): array
    {
        $personId = UuidV7::generate();
        $userId = UuidV7::generate();
        $membershipId = UuidV7::generate();
        $employeeId = UuidV7::generate();
        $employmentId = UuidV7::generate();
        $roleId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Self Service Fixture Employee',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $userId,
            'person_id' => $personId,
            'email' => sprintf('self-service-%s@educore.test', Str::lower(Str::random(10))),
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

        // `hr.leave.self.*` sengaja TIDAK di-auto-grant lewat seeder
        // katalog (lihat HrAuthorizationCatalogSeeder) — role terpisah
        // eksplisit diperlukan di sini. `hr.leave.approve` juga
        // disertakan supaya skenario SEQUENTIAL single-step bisa
        // diselesaikan aktor yang sama tanpa approver terpisah.
        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'self-service-test-' . Str::lower(Str::random(6)),
            'display_name' => 'Self Service Test Role',
            'description' => 'Test-only role granting self-service and approve permissions.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['hr.leave.self.read', 'hr.leave.self.request', 'hr.leave.approve'] as $permission) {
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

        return [$userId, $membershipId];
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
            'name' => 'Leave Self Service Tenant',
            'subdomain' => sprintf('leave-self-service-%s', Str::lower(Str::random(12))),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function issueToken(string $userId, string $membershipId): string
    {
        return app(TokenManagerInterface::class)
            ->issueToken($userId, $this->tenantId, ['membership_id' => $membershipId]);
    }
}
