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
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;
use Tests\TestCase;

final class LeaveRequestPersistenceTest extends TestCase
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

    public function test_request_can_be_created_with_default_draft_status(): void
    {
        $this->activateTenantContext($this->tenantAId);
        [$employmentId, $leaveTypeId] = $this->createEmploymentAndLeaveType();

        $request = LeaveRequest::create([
            'employment_id' => $employmentId,
            'leave_type_id' => $leaveTypeId,
            'starts_at' => '2026-06-01 00:00:00',
            'ends_at' => '2026-06-03 00:00:00',
            'request_timezone' => 'Asia/Jakarta',
            'requested_units' => 2,
            'unit' => LeaveType::UNIT_DAY,
        ]);

        $this->assertTrue(Str::isUuid($request->id));
        $this->assertSame(LeaveRequest::STATUS_DRAFT, $request->status);
    }

    public function test_check_constraint_rejects_ends_at_not_after_starts_at(): void
    {
        $this->activateTenantContext($this->tenantAId);
        [$employmentId, $leaveTypeId] = $this->createEmploymentAndLeaveType();

        $this->expectException(QueryException::class);

        LeaveRequest::create([
            'employment_id' => $employmentId,
            'leave_type_id' => $leaveTypeId,
            'starts_at' => '2026-06-03 00:00:00',
            'ends_at' => '2026-06-01 00:00:00',
            'request_timezone' => 'Asia/Jakarta',
            'requested_units' => 2,
            'unit' => LeaveType::UNIT_DAY,
        ]);
    }

    public function test_check_constraint_rejects_unknown_status(): void
    {
        $this->activateTenantContext($this->tenantAId);
        [$employmentId, $leaveTypeId] = $this->createEmploymentAndLeaveType();

        $this->expectException(QueryException::class);

        LeaveRequest::create([
            'employment_id' => $employmentId,
            'leave_type_id' => $leaveTypeId,
            'status' => 'UNKNOWN',
            'starts_at' => '2026-06-01 00:00:00',
            'ends_at' => '2026-06-03 00:00:00',
            'request_timezone' => 'Asia/Jakarta',
            'requested_units' => 2,
            'unit' => LeaveType::UNIT_DAY,
        ]);
    }

    public function test_check_constraint_rejects_zero_requested_units(): void
    {
        $this->activateTenantContext($this->tenantAId);
        [$employmentId, $leaveTypeId] = $this->createEmploymentAndLeaveType();

        $this->expectException(QueryException::class);

        LeaveRequest::create([
            'employment_id' => $employmentId,
            'leave_type_id' => $leaveTypeId,
            'starts_at' => '2026-06-01 00:00:00',
            'ends_at' => '2026-06-03 00:00:00',
            'request_timezone' => 'Asia/Jakarta',
            'requested_units' => 0,
            'unit' => LeaveType::UNIT_DAY,
        ]);
    }

    public function test_composite_foreign_key_rejects_employment_from_another_tenant(): void
    {
        $this->activateTenantContext($this->tenantBId);
        [$employmentFromTenantB] = $this->createEmploymentAndLeaveType();

        $this->activateTenantContext($this->tenantAId);
        $leaveTypeId = $this->createLeaveType();

        $this->expectException(QueryException::class);

        LeaveRequest::create([
            'employment_id' => $employmentFromTenantB,
            'leave_type_id' => $leaveTypeId,
            'starts_at' => '2026-06-01 00:00:00',
            'ends_at' => '2026-06-03 00:00:00',
            'request_timezone' => 'Asia/Jakarta',
            'requested_units' => 2,
            'unit' => LeaveType::UNIT_DAY,
        ]);
    }

    public function test_gist_exclusion_rejects_overlapping_approved_requests_for_same_employment(): void
    {
        $this->activateTenantContext($this->tenantAId);
        [$employmentId, $leaveTypeId] = $this->createEmploymentAndLeaveType();

        $this->createRequest($employmentId, $leaveTypeId, '2026-06-01', '2026-06-05', LeaveRequest::STATUS_APPROVED);

        $this->expectException(QueryException::class);

        // Overlap parsial dengan request pertama (03-07 vs 01-05).
        $this->createRequest($employmentId, $leaveTypeId, '2026-06-03', '2026-06-07', LeaveRequest::STATUS_APPROVED);
    }

    public function test_gist_exclusion_allows_overlap_when_one_request_is_not_approved(): void
    {
        $this->activateTenantContext($this->tenantAId);
        [$employmentId, $leaveTypeId] = $this->createEmploymentAndLeaveType();

        $this->createRequest($employmentId, $leaveTypeId, '2026-06-01', '2026-06-05', LeaveRequest::STATUS_APPROVED);

        // Overlap TAPI status DRAFT (bukan APPROVED) — sah ada
        // berdampingan, sesuai INV-HR-LEAVE-013: "Pending overlaps may
        // exist, but only one conflicting request can reach APPROVED."
        $overlapping = $this->createRequest($employmentId, $leaveTypeId, '2026-06-03', '2026-06-07', LeaveRequest::STATUS_DRAFT);

        $this->assertSame(LeaveRequest::STATUS_DRAFT, $overlapping->status);
    }

    public function test_gist_exclusion_allows_non_overlapping_approved_requests(): void
    {
        $this->activateTenantContext($this->tenantAId);
        [$employmentId, $leaveTypeId] = $this->createEmploymentAndLeaveType();

        $this->createRequest($employmentId, $leaveTypeId, '2026-06-01', '2026-06-05', LeaveRequest::STATUS_APPROVED);

        // Persis bersambung (ends_at Request 1 == starts_at Request 2)
        // — range exclusive di ujung kanan ('[)'), jadi TIDAK overlap.
        $second = $this->createRequest($employmentId, $leaveTypeId, '2026-06-05', '2026-06-10', LeaveRequest::STATUS_APPROVED);

        $this->assertSame(LeaveRequest::STATUS_APPROVED, $second->status);
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
            'name' => 'Leave Request Tenant',
            'subdomain' => sprintf(
                'leave-request-%s',
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

    private function createRequest(
        string $employmentId,
        string $leaveTypeId,
        string $startsAt,
        string $endsAt,
        string $status,
    ): LeaveRequest {
        return LeaveRequest::create([
            'employment_id' => $employmentId,
            'leave_type_id' => $leaveTypeId,
            'status' => $status,
            'starts_at' => $startsAt . ' 00:00:00',
            'ends_at' => $endsAt . ' 00:00:00',
            'request_timezone' => 'Asia/Jakarta',
            'requested_units' => 2,
            'unit' => LeaveType::UNIT_DAY,
        ]);
    }

    /**
     * @return array{0: string, 1: string} [employmentId, leaveTypeId]
     */
    private function createEmploymentAndLeaveType(): array
    {
        $tenantId = (string) app(TenantContextInterface::class)->getCurrentTenantId();

        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();
        $employeeId = UuidV7::generate();
        $employmentId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Leave Request Fixture Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employees')->insert([
            'id' => $employeeId,
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'nip' => null,
            'jabatan' => 'Guru',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employments')->insert([
            'id' => $employmentId,
            'tenant_id' => $tenantId,
            'employee_id' => $employeeId,
            'status' => 'ACTIVE',
            'start_date' => '2025-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$employmentId, $this->createLeaveType()];
    }
}
