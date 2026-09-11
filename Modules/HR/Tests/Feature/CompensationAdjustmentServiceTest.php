<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Exceptions\CompensationAdjustmentLifecycleException;
use Modules\HR\Models\CompensationAdjustment;
use Modules\HR\Models\Employment;
use Modules\HR\Services\CompensationAdjustmentService;
use Tests\TestCase;

final class CompensationAdjustmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private CompensationAdjustmentService $service;
    private string $tenantId;
    private string $employmentId;
    private string $requesterMembershipId;
    private string $approverMembershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CompensationAdjustmentService();
        $this->tenantId = $this->createTenant('Compensation Adjustment Service Tenant');
        $this->activateTenantContext($this->tenantId);

        $this->employmentId = $this->createActiveEmployment();
        $this->requesterMembershipId = $this->createMembership();
        $this->approverMembershipId = $this->createMembership();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // create
    // ---------------------------------------------------------------

    public function test_create_succeeds_with_valid_data(): void
    {
        $adjustment = $this->createAdjustmentViaService();

        $this->assertSame(CompensationAdjustment::STATUS_DRAFT, $adjustment->status);
        $this->assertSame($this->requesterMembershipId, $adjustment->requested_by_membership_id);
    }

    public function test_create_rejects_employment_not_active(): void
    {
        $plannedEmploymentId = $this->createEmploymentRow(Employment::STATUS_PLANNED);

        $this->expectException(CompensationAdjustmentLifecycleException::class);

        $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $plannedEmploymentId,
            requesterMembershipId: $this->requesterMembershipId,
            data: $this->baseAdjustmentData(),
        );
    }

    public function test_create_rejects_unknown_component(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $this->employmentId,
            requesterMembershipId: $this->requesterMembershipId,
            data: array_merge(
                $this->baseAdjustmentData(),
                ['compensation_component_id' => UuidV7::generate()],
            ),
        );
    }

    public function test_create_rejects_target_period_end_before_start(): void
    {
        $this->expectException(CompensationAdjustmentLifecycleException::class);

        $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $this->employmentId,
            requesterMembershipId: $this->requesterMembershipId,
            data: array_merge(
                $this->baseAdjustmentData(),
                [
                    'target_period_start' => '2026-06-01',
                    'target_period_end' => '2026-01-01',
                ],
            ),
        );
    }

    public function test_create_rejects_duplicate_idempotency_key(): void
    {
        $this->createAdjustmentViaService(['idempotency_key' => 'ADJ-SVC-DUP']);

        $this->expectException(CompensationAdjustmentLifecycleException::class);

        $this->createAdjustmentViaService(['idempotency_key' => 'ADJ-SVC-DUP']);
    }

    // ---------------------------------------------------------------
    // submit
    // ---------------------------------------------------------------

    public function test_submit_transitions_draft_to_submitted(): void
    {
        $adjustment = $this->createAdjustmentViaService();

        $submitted = $this->service->submit(
            $this->tenantId,
            $this->employmentId,
            $adjustment->id,
        );

        $this->assertSame(CompensationAdjustment::STATUS_SUBMITTED, $submitted->status);
    }

    public function test_submit_rejects_non_draft(): void
    {
        $adjustment = $this->createAdjustmentViaService();
        $this->service->submit($this->tenantId, $this->employmentId, $adjustment->id);

        $this->expectException(CompensationAdjustmentLifecycleException::class);

        $this->service->submit($this->tenantId, $this->employmentId, $adjustment->id);
    }

    // ---------------------------------------------------------------
    // approve
    // ---------------------------------------------------------------

    public function test_approve_transitions_submitted_to_approved(): void
    {
        $adjustment = $this->createAdjustmentViaService();
        $this->service->submit($this->tenantId, $this->employmentId, $adjustment->id);

        $approved = $this->service->approve(
            $this->tenantId,
            $this->employmentId,
            $adjustment->id,
            $this->approverMembershipId,
        );

        $this->assertSame(CompensationAdjustment::STATUS_APPROVED, $approved->status);
        $this->assertSame($this->approverMembershipId, $approved->approved_by_membership_id);
        $this->assertNotNull($approved->approved_at);
    }

    public function test_approve_rejects_non_submitted(): void
    {
        $adjustment = $this->createAdjustmentViaService();

        $this->expectException(CompensationAdjustmentLifecycleException::class);

        $this->service->approve(
            $this->tenantId,
            $this->employmentId,
            $adjustment->id,
            $this->approverMembershipId,
        );
    }

    public function test_approve_rejects_self_approval(): void
    {
        $adjustment = $this->createAdjustmentViaService();
        $this->service->submit($this->tenantId, $this->employmentId, $adjustment->id);

        $this->expectException(CompensationAdjustmentLifecycleException::class);
        $this->expectExceptionMessage('maker-checker');

        $this->service->approve(
            $this->tenantId,
            $this->employmentId,
            $adjustment->id,
            $this->requesterMembershipId,
        );
    }

    public function test_approve_rejects_inactive_approver(): void
    {
        $adjustment = $this->createAdjustmentViaService();
        $this->service->submit($this->tenantId, $this->employmentId, $adjustment->id);

        $inactiveApproverId = $this->createMembership(status: 'INACTIVE');

        $this->expectException(CompensationAdjustmentLifecycleException::class);

        $this->service->approve(
            $this->tenantId,
            $this->employmentId,
            $adjustment->id,
            $inactiveApproverId,
        );
    }

    // ---------------------------------------------------------------
    // reject
    // ---------------------------------------------------------------

    public function test_reject_transitions_submitted_to_rejected(): void
    {
        $adjustment = $this->createAdjustmentViaService();
        $this->service->submit($this->tenantId, $this->employmentId, $adjustment->id);

        $rejected = $this->service->reject(
            $this->tenantId,
            $this->employmentId,
            $adjustment->id,
            $this->approverMembershipId,
        );

        $this->assertSame(CompensationAdjustment::STATUS_REJECTED, $rejected->status);
        $this->assertNull($rejected->approved_by_membership_id);
    }

    public function test_reject_rejects_non_submitted(): void
    {
        $adjustment = $this->createAdjustmentViaService();

        $this->expectException(CompensationAdjustmentLifecycleException::class);

        $this->service->reject(
            $this->tenantId,
            $this->employmentId,
            $adjustment->id,
            $this->approverMembershipId,
        );
    }

    // ---------------------------------------------------------------
    // cancel
    // ---------------------------------------------------------------

    public function test_cancel_transitions_draft_to_cancelled(): void
    {
        $adjustment = $this->createAdjustmentViaService();

        $cancelled = $this->service->cancel(
            $this->tenantId,
            $this->employmentId,
            $adjustment->id,
        );

        $this->assertSame(CompensationAdjustment::STATUS_CANCELLED, $cancelled->status);
    }

    public function test_cancel_transitions_submitted_to_cancelled(): void
    {
        $adjustment = $this->createAdjustmentViaService();
        $this->service->submit($this->tenantId, $this->employmentId, $adjustment->id);

        $cancelled = $this->service->cancel(
            $this->tenantId,
            $this->employmentId,
            $adjustment->id,
        );

        $this->assertSame(CompensationAdjustment::STATUS_CANCELLED, $cancelled->status);
    }

    public function test_cancel_rejects_approved_adjustment(): void
    {
        $adjustment = $this->createAdjustmentViaService();
        $this->service->submit($this->tenantId, $this->employmentId, $adjustment->id);
        $this->service->approve(
            $this->tenantId,
            $this->employmentId,
            $adjustment->id,
            $this->approverMembershipId,
        );

        $this->expectException(CompensationAdjustmentLifecycleException::class);

        $this->service->cancel(
            $this->tenantId,
            $this->employmentId,
            $adjustment->id,
        );
    }

    public function test_approve_rejects_adjustment_from_different_employment(): void
    {
        $adjustment = $this->createAdjustmentViaService();
        $this->service->submit($this->tenantId, $this->employmentId, $adjustment->id);

        $otherEmploymentId = $this->createActiveEmployment();

        $this->expectException(CompensationAdjustmentLifecycleException::class);

        $this->service->approve(
            $this->tenantId,
            $otherEmploymentId,
            $adjustment->id,
            $this->approverMembershipId,
        );
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function activateTenantContext(string $tenantId): void
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        app(TenantContextInterface::class)->setCurrentTenant($tenant);
    }

    private function createTenant(string $name): string
    {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => $name,
            'subdomain' => sprintf(
                'compensation-adjustment-svc-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createMembership(string $status = 'ACTIVE'): string
    {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Compensation Adjustment Service Fixture Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $membershipId = UuidV7::generate();

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $membershipId;
    }

    private function createActiveEmployment(): string
    {
        return $this->createEmploymentRow(Employment::STATUS_ACTIVE);
    }

    private function createEmploymentRow(string $status): string
    {
        $membershipId = $this->createMembership();

        $employeeId = UuidV7::generate();

        DB::table('employees')->insert([
            'id' => $employeeId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $membershipId,
            'nip' => sprintf('NIP-%s', Str::upper(Str::random(8))),
            'jabatan' => 'GURU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employmentId = UuidV7::generate();

        DB::table('employments')->insert([
            'id' => $employmentId,
            'tenant_id' => $this->tenantId,
            'employee_id' => $employeeId,
            'status' => $status,
            'start_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employmentId;
    }

    /**
     * @return array{
     *     adjustment_type: string,
     *     amount: string,
     *     currency_code: string,
     *     target_period_start: string,
     *     target_period_end: string,
     *     reason: string,
     *     idempotency_key: string,
     * }
     */
    private function baseAdjustmentData(): array
    {
        return [
            'adjustment_type' => CompensationAdjustment::TYPE_ONE_TIME_EARNING,
            'amount' => '1000000.0000',
            'currency_code' => 'idr',
            'target_period_start' => '2026-01-01',
            'target_period_end' => '2026-01-31',
            'reason' => 'Uji coba layanan penyesuaian kompensasi.',
            'idempotency_key' => 'ADJ-SVC-' . Str::upper(Str::random(12)),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createAdjustmentViaService(array $overrides = []): CompensationAdjustment
    {
        return $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $this->employmentId,
            requesterMembershipId: $this->requesterMembershipId,
            data: array_merge($this->baseAdjustmentData(), $overrides),
        );
    }
}
