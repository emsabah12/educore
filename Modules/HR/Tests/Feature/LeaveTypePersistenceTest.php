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
use Modules\HR\Models\LeaveType;
use Tests\TestCase;

final class LeaveTypePersistenceTest extends TestCase
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

    public function test_leave_type_can_be_created_with_default_active_flag(): void
    {
        $this->activateTenantContext($this->tenantAId);

        $leaveType = LeaveType::create([
            'code' => 'ANNUAL',
            'name' => 'Cuti Tahunan',
            'category' => LeaveType::CATEGORY_LEAVE,
            'balance_mode' => LeaveType::BALANCE_MODE_BALANCE,
            'unit' => LeaveType::UNIT_DAY,
        ]);

        $this->assertTrue(Str::isUuid($leaveType->id));
        $this->assertTrue($leaveType->is_active);
        $this->assertTrue($leaveType->isBalanceBacked());
    }

    public function test_code_must_be_unique_per_tenant(): void
    {
        $this->activateTenantContext($this->tenantAId);

        $this->createLeaveType('DUP');

        $this->expectException(QueryException::class);

        $this->createLeaveType('DUP');
    }

    public function test_same_code_is_allowed_across_different_tenants(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $this->createLeaveType('SHARED');

        $this->activateTenantContext($this->tenantBId);
        $leaveTypeB = $this->createLeaveType('SHARED');

        $this->assertSame('SHARED', $leaveTypeB->code);
    }

    public function test_check_constraint_rejects_unknown_category(): void
    {
        $this->activateTenantContext($this->tenantAId);

        $this->expectException(QueryException::class);

        LeaveType::create([
            'code' => 'BAD_CATEGORY',
            'name' => 'Tipe Aneh',
            'category' => 'UNKNOWN',
            'balance_mode' => LeaveType::BALANCE_MODE_BALANCE,
            'unit' => LeaveType::UNIT_DAY,
        ]);
    }

    public function test_check_constraint_rejects_unknown_balance_mode(): void
    {
        $this->activateTenantContext($this->tenantAId);

        $this->expectException(QueryException::class);

        LeaveType::create([
            'code' => 'BAD_BALANCE_MODE',
            'name' => 'Tipe Aneh',
            'category' => LeaveType::CATEGORY_LEAVE,
            'balance_mode' => 'UNKNOWN',
            'unit' => LeaveType::UNIT_DAY,
        ]);
    }

    public function test_check_constraint_rejects_unknown_unit(): void
    {
        $this->activateTenantContext($this->tenantAId);

        $this->expectException(QueryException::class);

        LeaveType::create([
            'code' => 'BAD_UNIT',
            'name' => 'Tipe Aneh',
            'category' => LeaveType::CATEGORY_LEAVE,
            'balance_mode' => LeaveType::BALANCE_MODE_BALANCE,
            'unit' => 'UNKNOWN',
        ]);
    }

    public function test_permit_type_can_be_non_balance_backed(): void
    {
        $this->activateTenantContext($this->tenantAId);

        $leaveType = LeaveType::create([
            'code' => 'PERMIT_SICK_NOTE',
            'name' => 'Izin Sakit',
            'category' => LeaveType::CATEGORY_PERMIT,
            'balance_mode' => LeaveType::BALANCE_MODE_NONE,
            'unit' => LeaveType::UNIT_DAY,
        ]);

        $this->assertFalse($leaveType->isBalanceBacked());
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
            'name' => 'Leave Type Tenant',
            'subdomain' => sprintf(
                'leave-type-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createLeaveType(string $code): LeaveType
    {
        return LeaveType::create([
            'code' => $code,
            'name' => 'Cuti Uji '.Str::random(6),
            'category' => LeaveType::CATEGORY_LEAVE,
            'balance_mode' => LeaveType::BALANCE_MODE_BALANCE,
            'unit' => LeaveType::UNIT_DAY,
        ]);
    }
}
