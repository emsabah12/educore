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
use Modules\HR\Exceptions\CompensationLifecycleException;
use Modules\HR\Models\CompensationAssignment;
use Modules\HR\Models\CompensationComponent;
use Modules\HR\Models\Employment;
use Modules\HR\Models\EmploymentPositionAssignment;
use Modules\HR\Models\Position;
use Modules\HR\Services\CompensationAssignmentService;
use Tests\TestCase;

final class CompensationAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private CompensationAssignmentService $service;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CompensationAssignmentService();
        $this->tenantId = $this->createTenant('Compensation Service Tenant');
        $this->activateTenantContext($this->tenantId);
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // createDraft
    // ---------------------------------------------------------------

    public function test_create_draft_succeeds_with_fixed_amount(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;

        $assignment = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'idr',
                'effective_from' => '2026-01-01',
            ],
        );

        $this->assertSame(CompensationAssignment::STATUS_DRAFT, $assignment->status);
        $this->assertSame('5000000.0000', $assignment->amount);
        $this->assertNull($assignment->rate);
    }

    public function test_create_draft_succeeds_with_rate_per_unit(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createRateComponent('TEACHING_HOUR_RATE')->id;

        $assignment = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'rate' => '50000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );

        $this->assertSame('50000.0000', $assignment->rate);
        $this->assertNull($assignment->amount);
    }

    public function test_create_draft_normalizes_currency_code_to_uppercase(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;

        $assignment = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'idr',
                'effective_from' => '2026-01-01',
            ],
        );

        $this->assertSame('IDR', $assignment->currency_code);
    }

    public function test_create_draft_rejects_employment_not_active(): void
    {
        $employmentId = $this->createPlannedEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;

        $this->expectException(CompensationLifecycleException::class);

        $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_draft_rejects_inactive_component(): void
    {
        $employmentId = $this->createActiveEmployment();
        $component = $this->createFixedComponent('BASE_SALARY');
        $component->update(['is_active' => false]);

        $this->expectException(CompensationLifecycleException::class);

        $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $component->id,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_draft_rejects_fixed_amount_component_when_rate_given(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;

        $this->expectException(CompensationLifecycleException::class);

        $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'rate' => '50000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_draft_rejects_rate_component_when_amount_given(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createRateComponent('TEACHING_HOUR_RATE')->id;

        $this->expectException(CompensationLifecycleException::class);

        $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_draft_rejects_effective_from_before_employment_start_date(): void
    {
        $employmentId = $this->createActiveEmployment(startDate: '2026-03-01');
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;

        $this->expectException(CompensationLifecycleException::class);

        $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_draft_rejects_unknown_component(): void
    {
        $employmentId = $this->createActiveEmployment();

        $this->expectException(ModelNotFoundException::class);

        $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => UuidV7::generate(),
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_draft_rejects_position_assignment_from_different_employment(): void
    {
        $employmentId = $this->createActiveEmployment();
        $otherEmploymentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('POSITION_ALLOWANCE')->id;
        $foreignPositionAssignmentId = $this->createPositionAssignment($otherEmploymentId);

        $this->expectException(CompensationLifecycleException::class);

        $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'employment_position_assignment_id' => $foreignPositionAssignmentId,
                'amount' => '500000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );
    }

    // ---------------------------------------------------------------
    // approve
    // ---------------------------------------------------------------

    public function test_approve_transitions_draft_to_approved(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $approverMembershipId = $this->createMembership();

        $draft = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );

        $approved = $this->service->approve(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $draft->id,
            approverMembershipId: $approverMembershipId,
        );

        $this->assertSame(CompensationAssignment::STATUS_APPROVED, $approved->status);
        $this->assertSame($approverMembershipId, $approved->approved_by_membership_id);
        $this->assertNotNull($approved->approved_at);
    }

    public function test_approve_rejects_non_draft_assignment(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $approverMembershipId = $this->createMembership();

        $draft = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );

        $this->service->approve(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $draft->id,
            approverMembershipId: $approverMembershipId,
        );

        $this->expectException(CompensationLifecycleException::class);

        $this->service->approve(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $draft->id,
            approverMembershipId: $approverMembershipId,
        );
    }

    public function test_approve_rejects_assignment_from_different_employment(): void
    {
        $employmentId = $this->createActiveEmployment();
        $otherEmploymentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $approverMembershipId = $this->createMembership();

        $draft = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );

        $this->expectException(CompensationLifecycleException::class);

        $this->service->approve(
            tenantId: $this->tenantId,
            employmentId: $otherEmploymentId,
            assignmentId: $draft->id,
            approverMembershipId: $approverMembershipId,
        );
    }

    public function test_approve_rejects_inactive_approver_membership(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $approverMembershipId = $this->createMembership(status: 'INACTIVE');

        $draft = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );

        $this->expectException(CompensationLifecycleException::class);

        $this->service->approve(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $draft->id,
            approverMembershipId: $approverMembershipId,
        );
    }

    public function test_approve_rejects_overlapping_approved_assignment(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $approverMembershipId = $this->createMembership();

        $first = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );

        $this->service->approve(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $first->id,
            approverMembershipId: $approverMembershipId,
        );

        $second = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '6000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-03-01',
            ],
        );

        $this->expectException(CompensationLifecycleException::class);

        $this->service->approve(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $second->id,
            approverMembershipId: $approverMembershipId,
        );
    }

    // ---------------------------------------------------------------
    // end
    // ---------------------------------------------------------------

    public function test_end_transitions_approved_to_ended_and_sets_effective_to(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $approverMembershipId = $this->createMembership();

        $draft = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );

        $approved = $this->service->approve(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $draft->id,
            approverMembershipId: $approverMembershipId,
        );

        $ended = $this->service->end(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $approved->id,
            endDate: '2026-06-30',
        );

        $this->assertSame(CompensationAssignment::STATUS_ENDED, $ended->status);
        $this->assertSame('2026-06-30', $ended->effective_to->toDateString());
        $this->assertNotNull($ended->ended_at);
    }

    public function test_end_rejects_non_approved_assignment(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;

        $draft = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );

        $this->expectException(CompensationLifecycleException::class);

        $this->service->end(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $draft->id,
            endDate: '2026-06-30',
        );
    }

    public function test_end_rejects_assignment_with_already_fixed_effective_to(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $approverMembershipId = $this->createMembership();

        $draft = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
            ],
        );

        $approved = $this->service->approve(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $draft->id,
            approverMembershipId: $approverMembershipId,
        );

        $this->expectException(CompensationLifecycleException::class);

        $this->service->end(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $approved->id,
            endDate: '2026-06-30',
        );
    }

    public function test_end_rejects_end_date_before_effective_from(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $approverMembershipId = $this->createMembership();

        $draft = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-06-01',
            ],
        );

        $approved = $this->service->approve(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $draft->id,
            approverMembershipId: $approverMembershipId,
        );

        $this->expectException(CompensationLifecycleException::class);

        $this->service->end(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $approved->id,
            endDate: '2026-01-01',
        );
    }

    public function test_end_rejects_assignment_from_different_employment(): void
    {
        $employmentId = $this->createActiveEmployment();
        $otherEmploymentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $approverMembershipId = $this->createMembership();

        $draft = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );

        $approved = $this->service->approve(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $draft->id,
            approverMembershipId: $approverMembershipId,
        );

        $this->expectException(CompensationLifecycleException::class);

        $this->service->end(
            tenantId: $this->tenantId,
            employmentId: $otherEmploymentId,
            assignmentId: $approved->id,
            endDate: '2026-06-30',
        );
    }

    public function test_ended_assignment_frees_up_period_for_new_approved_assignment(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $approverMembershipId = $this->createMembership();

        $first = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );

        $approvedFirst = $this->service->approve(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $first->id,
            approverMembershipId: $approverMembershipId,
        );

        $this->service->end(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $approvedFirst->id,
            endDate: '2026-06-30',
        );

        $second = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '6000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-07-01',
            ],
        );

        $approvedSecond = $this->service->approve(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $second->id,
            approverMembershipId: $approverMembershipId,
        );

        $this->assertSame(
            CompensationAssignment::STATUS_APPROVED,
            $approvedSecond->status,
        );
    }

    // ---------------------------------------------------------------
    // correct
    // ---------------------------------------------------------------

    public function test_correct_marks_original_as_superseded_and_creates_draft_replacement(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $approverMembershipId = $this->createMembership();

        $draft = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );

        $original = $this->service->approve(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $draft->id,
            approverMembershipId: $approverMembershipId,
        );

        $replacement = $this->service->correct(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            originalAssignmentId: $original->id,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5500000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
                'reason' => 'Koreksi: nominal yang disetujui sebelumnya keliru.',
            ],
        );

        $this->assertSame(CompensationAssignment::STATUS_DRAFT, $replacement->status);
        $this->assertSame($original->id, $replacement->supersedes_assignment_id);
        $this->assertSame('5500000.0000', $replacement->amount);

        $original->refresh();
        $this->assertSame(CompensationAssignment::STATUS_SUPERSEDED, $original->status);
    }

    public function test_correct_replacement_can_be_approved(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $approverMembershipId = $this->createMembership();

        $draft = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );

        $original = $this->service->approve(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $draft->id,
            approverMembershipId: $approverMembershipId,
        );

        $replacement = $this->service->correct(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            originalAssignmentId: $original->id,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5500000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );

        $approvedReplacement = $this->service->approve(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $replacement->id,
            approverMembershipId: $approverMembershipId,
        );

        $this->assertSame(CompensationAssignment::STATUS_APPROVED, $approvedReplacement->status);
        $this->assertSame($original->id, $approvedReplacement->supersedes_assignment_id);
    }

    public function test_correct_rejects_non_approved_original(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;

        $draft = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );

        $this->expectException(CompensationLifecycleException::class);

        $this->service->correct(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            originalAssignmentId: $draft->id,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5500000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_correct_rejects_original_from_different_employment(): void
    {
        $employmentId = $this->createActiveEmployment();
        $otherEmploymentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $approverMembershipId = $this->createMembership();

        $draft = $this->service->createDraft(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5000000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );

        $original = $this->service->approve(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            assignmentId: $draft->id,
            approverMembershipId: $approverMembershipId,
        );

        $this->expectException(CompensationLifecycleException::class);

        $this->service->correct(
            tenantId: $this->tenantId,
            employmentId: $otherEmploymentId,
            originalAssignmentId: $original->id,
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5500000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_correct_rejects_unknown_original(): void
    {
        $employmentId = $this->createActiveEmployment();
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;

        $this->expectException(ModelNotFoundException::class);

        $this->service->correct(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            originalAssignmentId: UuidV7::generate(),
            data: [
                'compensation_component_id' => $componentId,
                'amount' => '5500000.0000',
                'currency_code' => 'IDR',
                'effective_from' => '2026-01-01',
            ],
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
                'compensation-svc-%s',
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
            'name' => 'Compensation Service Fixture Person',
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

    private function createFixedComponent(string $code): CompensationComponent
    {
        return CompensationComponent::create([
            'code' => $code . '-' . Str::upper(Str::random(4)),
            'name' => 'Komponen Uji ' . $code,
            'category' => CompensationComponent::CATEGORY_BASE_PAY,
            'value_mode' => CompensationComponent::VALUE_MODE_FIXED_AMOUNT,
            'unit_code' => null,
            'periodicity' => 'MONTHLY',
        ]);
    }

    private function createRateComponent(string $code): CompensationComponent
    {
        return CompensationComponent::create([
            'code' => $code . '-' . Str::upper(Str::random(4)),
            'name' => 'Komponen Uji ' . $code,
            'category' => CompensationComponent::CATEGORY_RATE,
            'value_mode' => CompensationComponent::VALUE_MODE_RATE_PER_UNIT,
            'unit_code' => 'HOUR',
            'periodicity' => 'PER_UNIT',
        ]);
    }

    private function createPositionAssignment(string $employmentId): string
    {
        $positionId = Position::create([
            'code' => 'POS-' . Str::upper(Str::random(6)),
            'name' => 'Posisi Uji Kompensasi',
            'is_active' => true,
        ])->id;

        return EmploymentPositionAssignment::create([
            'employment_id' => $employmentId,
            'position_id' => $positionId,
            'employment_placement_id' => null,
            'effective_from' => '2026-01-01',
        ])->id;
    }

    private function createActiveEmployment(string $startDate = '2026-01-01'): string
    {
        $employmentId = $this->createEmploymentRow(
            Employment::STATUS_ACTIVE,
            $startDate,
        );

        return $employmentId;
    }

    private function createPlannedEmployment(string $startDate = '2026-01-01'): string
    {
        return $this->createEmploymentRow(
            Employment::STATUS_PLANNED,
            $startDate,
        );
    }

    private function createEmploymentRow(
        string $status,
        string $startDate,
    ): string {
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
            'start_date' => $startDate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employmentId;
    }
}
