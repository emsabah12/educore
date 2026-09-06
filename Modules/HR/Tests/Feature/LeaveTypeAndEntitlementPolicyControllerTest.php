<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\HR\Database\Seeders\HrAuthorizationCatalogSeeder;
use Modules\HR\Models\LeaveType;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class LeaveTypeAndEntitlementPolicyControllerTest extends TestCase
{
    use RefreshDatabase;
    use GrantsAuthorizationRole;

    private string $tenantId;
    private string $operatorUserId;
    private string $operatorMembershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HrAuthorizationCatalogSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();

        $this->createTenantFixture();
        $this->createOperatorFixture();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_store_leave_type_creates_record(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-types.store', [], false),
                $this->leaveTypePayload('ANNUAL-HTTP'),
            );

        $response
            ->assertCreated()
            ->assertJsonPath('data.code', 'ANNUAL-HTTP')
            ->assertJsonPath('data.is_active', true);
    }

    public function test_store_leave_type_is_forbidden_without_permission(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-types.store', [], false),
                $this->leaveTypePayload('ANNUAL-HTTP-2'),
            );

        $response->assertForbidden();
    }

    public function test_update_leave_type_rejects_immutable_field_change_once_referenced(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $leaveTypeId = $this->createLeaveTypeViaApi('IMMUTABLE-HTTP');

        // Rujuk LeaveType ini lewat Entitlement Policy supaya "sudah
        // pernah dipakai" — memicu pembekuan field di service.
        $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-entitlement-policies.store', [], false),
                [
                    'leave_type_id' => $leaveTypeId,
                    'period_basis' => 'CALENDAR_YEAR',
                    'grant_units' => 12,
                    'effective_from' => '2026-01-01',
                ],
            )->assertCreated();

        $response = $this
            ->withToken($this->issueToken())
            ->patchJson(
                route('api.v1.hr.leave-types.update', ['leaveTypeId' => $leaveTypeId], false),
                ['unit' => LeaveType::UNIT_HOUR],
            );

        $response->assertConflict();
    }

    public function test_deactivate_leave_type_sets_inactive(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $leaveTypeId = $this->createLeaveTypeViaApi('DEACTIVATE-HTTP');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-types.deactivate', ['leaveTypeId' => $leaveTypeId], false),
            );

        $response
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_store_entitlement_policy_creates_record(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $leaveTypeId = $this->createLeaveTypeViaApi('POLICY-HTTP');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-entitlement-policies.store', [], false),
                [
                    'leave_type_id' => $leaveTypeId,
                    'period_basis' => 'CALENDAR_YEAR',
                    'grant_units' => 12,
                    'effective_from' => '2026-01-01',
                ],
            );

        $response
            ->assertCreated()
            ->assertJsonPath('data.grant_units', '12.00');
    }

    public function test_store_entitlement_policy_is_forbidden_without_permission(): void
    {
        $leaveTypeId = UuidV7::generate();
        DB::table('leave_types')->insert([
            'id' => $leaveTypeId,
            'tenant_id' => $this->tenantId,
            'code' => 'POLICY-HTTP-2',
            'name' => 'Cuti Uji HTTP Forbidden',
            'category' => LeaveType::CATEGORY_LEAVE,
            'balance_mode' => LeaveType::BALANCE_MODE_BALANCE,
            'unit' => LeaveType::UNIT_DAY,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-entitlement-policies.store', [], false),
                [
                    'leave_type_id' => $leaveTypeId,
                    'period_basis' => 'CALENDAR_YEAR',
                    'grant_units' => 12,
                    'effective_from' => '2026-01-01',
                ],
            );

        $response->assertForbidden();
    }

    public function test_index_lists_leave_types(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);
        $this->createLeaveTypeViaApi('INDEX-HTTP');

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(route('api.v1.hr.leave-types.index', [], false));

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }

    /**
     * @return array{
     *     code: string,
     *     name: string,
     *     category: string,
     *     balance_mode: string,
     *     unit: string,
     * }
     */
    private function leaveTypePayload(string $code): array
    {
        return [
            'code' => $code,
            'name' => 'Cuti Uji HTTP ' . Str::random(4),
            'category' => LeaveType::CATEGORY_LEAVE,
            'balance_mode' => LeaveType::BALANCE_MODE_BALANCE,
            'unit' => LeaveType::UNIT_DAY,
        ];
    }

    private function createLeaveTypeViaApi(string $code): string
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.leave-types.store', [], false),
                $this->leaveTypePayload($code),
            );

        return $response->json('data.id');
    }

    private function createTenantFixture(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Leave Type HTTP Tenant',
            'subdomain' => sprintf(
                'leave-type-http-%s',
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
            'name' => 'Leave Type HTTP Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => sprintf(
                'leave-type-operator-%s@educore.test',
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
