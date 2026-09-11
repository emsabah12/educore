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
use Modules\HR\Models\LeaveType;
use Modules\HR\Services\LeaveBalanceService;
use Modules\HR\Services\LeaveEntitlementPolicyService;
use Modules\HR\Services\LeaveEntitlementService;
use Tests\TestCase;

final class LeaveEntitlementAndBalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeaveEntitlementService $entitlementService;

    private LeaveBalanceService $balanceService;

    private LeaveEntitlementPolicyService $policyService;

    private string $tenantId;

    private string $leaveTypeId;

    private string $employmentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->balanceService = new LeaveBalanceService;
        $this->policyService = new LeaveEntitlementPolicyService;
        $this->entitlementService = new LeaveEntitlementService(
            $this->policyService,
            $this->balanceService,
        );

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

    public function test_generate_for_period_creates_entitlement_with_initial_grant(): void
    {
        $this->policyService->createPolicy($this->tenantId, [
            'leave_type_id' => $this->leaveTypeId,
            'period_basis' => 'CALENDAR_YEAR',
            'grant_units' => 12,
            'effective_from' => '2026-01-01',
        ]);

        $entitlement = $this->entitlementService->generateForPeriod(
            $this->tenantId,
            $this->employmentId,
            $this->leaveTypeId,
            '2026-01-01',
            '2026-12-31',
        );

        $this->assertSame(
            '12.00',
            $this->balanceService->balance($this->tenantId, $entitlement->id),
        );
        $this->assertNotNull($entitlement->entitlement_policy_id);
    }

    public function test_generate_for_period_rejects_duplicate_period(): void
    {
        $this->policyService->createPolicy($this->tenantId, [
            'leave_type_id' => $this->leaveTypeId,
            'period_basis' => 'CALENDAR_YEAR',
            'grant_units' => 12,
            'effective_from' => '2026-01-01',
        ]);

        $this->entitlementService->generateForPeriod(
            $this->tenantId,
            $this->employmentId,
            $this->leaveTypeId,
            '2026-01-01',
            '2026-12-31',
        );

        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/already exists/');

        $this->entitlementService->generateForPeriod(
            $this->tenantId,
            $this->employmentId,
            $this->leaveTypeId,
            '2026-01-01',
            '2026-12-31',
        );
    }

    public function test_create_manual_entitlement_has_null_policy_reference(): void
    {
        $entitlement = $this->entitlementService->createManualEntitlement(
            $this->tenantId,
            $this->employmentId,
            $this->leaveTypeId,
            '2026-01-01',
            '2026-12-31',
            '8',
            null,
            'Migrasi data lama.',
        );

        $this->assertNull($entitlement->entitlement_policy_id);
        $this->assertSame(
            '8.00',
            $this->balanceService->balance($this->tenantId, $entitlement->id),
        );
    }

    public function test_create_manual_entitlement_rejects_non_balance_backed_leave_type(): void
    {
        $permitTypeId = LeaveType::create([
            'code' => 'PERMIT_NO_BALANCE',
            'name' => 'Izin Tanpa Saldo',
            'category' => LeaveType::CATEGORY_PERMIT,
            'balance_mode' => LeaveType::BALANCE_MODE_NONE,
            'unit' => LeaveType::UNIT_DAY,
        ])->id;

        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/not BALANCE-backed/');

        $this->entitlementService->createManualEntitlement(
            $this->tenantId,
            $this->employmentId,
            $permitTypeId,
            '2026-01-01',
            '2026-12-31',
            '8',
        );
    }

    public function test_adjust_increases_balance_and_records_ledger_entry(): void
    {
        $entitlement = $this->entitlementService->createManualEntitlement(
            $this->tenantId,
            $this->employmentId,
            $this->leaveTypeId,
            '2026-01-01',
            '2026-12-31',
            '10',
        );

        $this->balanceService->adjust(
            $this->tenantId,
            $entitlement->id,
            '2',
            'adjust:test-1',
            null,
            'Koreksi tambahan cuti.',
        );

        $this->assertSame(
            '12.00',
            $this->balanceService->balance($this->tenantId, $entitlement->id),
        );
    }

    public function test_adjust_rejects_negative_resulting_balance(): void
    {
        $entitlement = $this->entitlementService->createManualEntitlement(
            $this->tenantId,
            $this->employmentId,
            $this->leaveTypeId,
            '2026-01-01',
            '2026-12-31',
            '5',
        );

        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/negative balance/');

        $this->balanceService->adjust(
            $this->tenantId,
            $entitlement->id,
            '-10',
            'adjust:test-negative',
        );
    }

    public function test_adjust_rejects_duplicate_idempotency_key(): void
    {
        $entitlement = $this->entitlementService->createManualEntitlement(
            $this->tenantId,
            $this->employmentId,
            $this->leaveTypeId,
            '2026-01-01',
            '2026-12-31',
            '10',
        );

        $this->balanceService->adjust(
            $this->tenantId,
            $entitlement->id,
            '1',
            'adjust:duplicate-key',
        );

        $this->expectException(LeaveLifecycleException::class);
        $this->expectExceptionMessageMatches('/already applied/');

        $this->balanceService->adjust(
            $this->tenantId,
            $entitlement->id,
            '1',
            'adjust:duplicate-key',
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
            'name' => 'Leave Entitlement Service Tenant',
            'subdomain' => sprintf(
                'leave-entitlement-svc-%s',
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
            'name' => 'Leave Entitlement Service Fixture Person',
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
}
