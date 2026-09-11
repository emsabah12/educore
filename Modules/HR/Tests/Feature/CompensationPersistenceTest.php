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
use Modules\HR\Models\CompensationAssignment;
use Modules\HR\Models\CompensationComponent;
use Modules\HR\Models\Employment;
use Modules\HR\Models\EmploymentPositionAssignment;
use Modules\HR\Models\Position;
use Tests\TestCase;

final class CompensationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->createTenant('Compensation Persistence Tenant');
        $this->activateTenantContext($this->tenantId);
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // CompensationComponent
    // ---------------------------------------------------------------

    public function test_component_can_be_created_with_fixed_amount_mode(): void
    {
        $component = CompensationComponent::create([
            'code' => 'BASE_SALARY',
            'name' => 'Gaji Pokok',
            'category' => CompensationComponent::CATEGORY_BASE_PAY,
            'value_mode' => CompensationComponent::VALUE_MODE_FIXED_AMOUNT,
            'unit_code' => null,
            'periodicity' => 'MONTHLY',
        ]);

        $this->assertSame('BASE_SALARY', $component->code);
        $this->assertNull($component->unit_code);
    }

    public function test_component_can_be_created_with_rate_per_unit_mode(): void
    {
        $component = CompensationComponent::create([
            'code' => 'TEACHING_HOUR_RATE',
            'name' => 'Tarif Jam Mengajar',
            'category' => CompensationComponent::CATEGORY_RATE,
            'value_mode' => CompensationComponent::VALUE_MODE_RATE_PER_UNIT,
            'unit_code' => 'HOUR',
            'periodicity' => 'PER_UNIT',
        ]);

        $this->assertSame('HOUR', $component->unit_code);
    }

    public function test_database_rejects_rate_per_unit_component_without_unit_code(): void
    {
        $this->expectException(QueryException::class);

        CompensationComponent::create([
            'code' => 'INVALID_RATE',
            'name' => 'Rate Tanpa Unit',
            'category' => CompensationComponent::CATEGORY_RATE,
            'value_mode' => CompensationComponent::VALUE_MODE_RATE_PER_UNIT,
            'unit_code' => null,
            'periodicity' => 'PER_UNIT',
        ]);
    }

    public function test_database_rejects_fixed_amount_component_with_unit_code(): void
    {
        $this->expectException(QueryException::class);

        CompensationComponent::create([
            'code' => 'INVALID_FIXED',
            'name' => 'Fixed Dengan Unit',
            'category' => CompensationComponent::CATEGORY_BASE_PAY,
            'value_mode' => CompensationComponent::VALUE_MODE_FIXED_AMOUNT,
            'unit_code' => 'HOUR',
            'periodicity' => 'MONTHLY',
        ]);
    }

    public function test_database_rejects_duplicate_component_code_within_same_tenant(): void
    {
        $this->createFixedComponent('BASE_SALARY');

        $this->expectException(QueryException::class);

        $this->createFixedComponent('BASE_SALARY');
    }

    public function test_same_component_code_is_allowed_across_different_tenants(): void
    {
        $this->createFixedComponent('BASE_SALARY');

        $otherTenantId = $this->createTenant('Compensation Other Tenant');
        $this->activateTenantContext($otherTenantId);

        $component = $this->createFixedComponent('BASE_SALARY');

        $this->assertSame($otherTenantId, $component->tenant_id);
    }

    // ---------------------------------------------------------------
    // CompensationAssignment — value rules
    // ---------------------------------------------------------------

    public function test_assignment_can_be_created_with_fixed_amount(): void
    {
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $employmentId = $this->createEmployment();

        $assignment = CompensationAssignment::create([
            'employment_id' => $employmentId,
            'compensation_component_id' => $componentId,
            'status' => CompensationAssignment::STATUS_DRAFT,
            'amount' => '5000000.0000',
            'currency_code' => 'IDR',
            'effective_from' => '2026-01-01',
        ]);

        $this->assertSame('5000000.0000', $assignment->amount);
        $this->assertNull($assignment->rate);
    }

    public function test_database_rejects_assignment_with_both_amount_and_rate(): void
    {
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $employmentId = $this->createEmployment();

        $this->expectException(QueryException::class);

        CompensationAssignment::create([
            'employment_id' => $employmentId,
            'compensation_component_id' => $componentId,
            'status' => CompensationAssignment::STATUS_DRAFT,
            'amount' => '5000000.0000',
            'rate' => '50000.0000',
            'currency_code' => 'IDR',
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_database_rejects_assignment_with_neither_amount_nor_rate(): void
    {
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $employmentId = $this->createEmployment();

        $this->expectException(QueryException::class);

        CompensationAssignment::create([
            'employment_id' => $employmentId,
            'compensation_component_id' => $componentId,
            'status' => CompensationAssignment::STATUS_DRAFT,
            'currency_code' => 'IDR',
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_database_rejects_zero_amount(): void
    {
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $employmentId = $this->createEmployment();

        $this->expectException(QueryException::class);

        CompensationAssignment::create([
            'employment_id' => $employmentId,
            'compensation_component_id' => $componentId,
            'status' => CompensationAssignment::STATUS_DRAFT,
            'amount' => '0.0000',
            'currency_code' => 'IDR',
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_database_rejects_invalid_currency_code(): void
    {
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $employmentId = $this->createEmployment();

        $this->expectException(QueryException::class);

        CompensationAssignment::create([
            'employment_id' => $employmentId,
            'compensation_component_id' => $componentId,
            'status' => CompensationAssignment::STATUS_DRAFT,
            'amount' => '5000000.0000',
            'currency_code' => 'idr',
            'effective_from' => '2026-01-01',
        ]);
    }

    // ---------------------------------------------------------------
    // CompensationAssignment — approval field consistency
    // ---------------------------------------------------------------

    public function test_database_rejects_approved_status_without_approval_fields(): void
    {
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $employmentId = $this->createEmployment();

        $this->expectException(QueryException::class);

        CompensationAssignment::create([
            'employment_id' => $employmentId,
            'compensation_component_id' => $componentId,
            'status' => CompensationAssignment::STATUS_APPROVED,
            'amount' => '5000000.0000',
            'currency_code' => 'IDR',
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_database_rejects_draft_status_with_approval_fields(): void
    {
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $employmentId = $this->createEmployment();
        $membershipId = $this->createMembership();

        $this->expectException(QueryException::class);

        CompensationAssignment::create([
            'employment_id' => $employmentId,
            'compensation_component_id' => $componentId,
            'status' => CompensationAssignment::STATUS_DRAFT,
            'amount' => '5000000.0000',
            'currency_code' => 'IDR',
            'effective_from' => '2026-01-01',
            'approved_by_membership_id' => $membershipId,
            'approved_at' => now(),
        ]);
    }

    public function test_assignment_can_be_approved_with_required_fields(): void
    {
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $employmentId = $this->createEmployment();
        $membershipId = $this->createMembership();

        $assignment = CompensationAssignment::create([
            'employment_id' => $employmentId,
            'compensation_component_id' => $componentId,
            'status' => CompensationAssignment::STATUS_APPROVED,
            'amount' => '5000000.0000',
            'currency_code' => 'IDR',
            'effective_from' => '2026-01-01',
            'approved_by_membership_id' => $membershipId,
            'approved_at' => now(),
        ]);

        $this->assertSame(
            CompensationAssignment::STATUS_APPROVED,
            $assignment->status,
        );
    }

    // ---------------------------------------------------------------
    // CompensationAssignment — approved overlap exclusion
    // ---------------------------------------------------------------

    public function test_database_rejects_overlapping_approved_assignments_unscoped(): void
    {
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $employmentId = $this->createEmployment();
        $membershipId = $this->createMembership();

        $this->createApprovedAssignment(
            $employmentId,
            $componentId,
            $membershipId,
            '2026-01-01',
            null,
        );

        $this->expectException(QueryException::class);

        $this->createApprovedAssignment(
            $employmentId,
            $componentId,
            $membershipId,
            '2026-02-01',
            '2026-06-30',
        );
    }

    public function test_database_allows_non_overlapping_approved_assignments(): void
    {
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $employmentId = $this->createEmployment();
        $membershipId = $this->createMembership();

        $this->createApprovedAssignment(
            $employmentId,
            $componentId,
            $membershipId,
            '2026-01-01',
            '2026-01-31',
        );

        $second = $this->createApprovedAssignment(
            $employmentId,
            $componentId,
            $membershipId,
            '2026-02-01',
            null,
        );

        $this->assertNotNull($second->id);
    }

    public function test_database_allows_overlapping_draft_assignments(): void
    {
        $componentId = $this->createFixedComponent('BASE_SALARY')->id;
        $employmentId = $this->createEmployment();

        CompensationAssignment::create([
            'employment_id' => $employmentId,
            'compensation_component_id' => $componentId,
            'status' => CompensationAssignment::STATUS_DRAFT,
            'amount' => '5000000.0000',
            'currency_code' => 'IDR',
            'effective_from' => '2026-01-01',
        ]);

        $second = CompensationAssignment::create([
            'employment_id' => $employmentId,
            'compensation_component_id' => $componentId,
            'status' => CompensationAssignment::STATUS_DRAFT,
            'amount' => '6000000.0000',
            'currency_code' => 'IDR',
            'effective_from' => '2026-01-01',
        ]);

        $this->assertNotNull($second->id);
    }

    public function test_database_allows_same_period_when_scoped_to_different_position_assignments(): void
    {
        $componentId = $this->createFixedComponent('POSITION_ALLOWANCE')->id;
        $employmentId = $this->createEmployment();
        $membershipId = $this->createMembership();

        $positionAssignmentOneId = $this->createPositionAssignment($employmentId);
        $positionAssignmentTwoId = $this->createPositionAssignment($employmentId);

        $this->createApprovedAssignment(
            $employmentId,
            $componentId,
            $membershipId,
            '2026-01-01',
            null,
            $positionAssignmentOneId,
        );

        $second = $this->createApprovedAssignment(
            $employmentId,
            $componentId,
            $membershipId,
            '2026-01-01',
            null,
            $positionAssignmentTwoId,
        );

        $this->assertNotNull($second->id);
    }

    public function test_database_rejects_overlapping_approved_assignments_scoped_to_same_position_assignment(): void
    {
        $componentId = $this->createFixedComponent('POSITION_ALLOWANCE')->id;
        $employmentId = $this->createEmployment();
        $membershipId = $this->createMembership();
        $positionAssignmentId = $this->createPositionAssignment($employmentId);

        $this->createApprovedAssignment(
            $employmentId,
            $componentId,
            $membershipId,
            '2026-01-01',
            null,
            $positionAssignmentId,
        );

        $this->expectException(QueryException::class);

        $this->createApprovedAssignment(
            $employmentId,
            $componentId,
            $membershipId,
            '2026-03-01',
            null,
            $positionAssignmentId,
        );
    }

    // ---------------------------------------------------------------
    // Tenant isolation / hard-delete protection
    // ---------------------------------------------------------------

    public function test_composite_foreign_key_rejects_component_from_another_tenant(): void
    {
        $otherTenantId = $this->createTenant('Compensation Foreign Tenant');
        $this->activateTenantContext($otherTenantId);
        $foreignComponentId = $this->createFixedComponent('BASE_SALARY')->id;

        $this->activateTenantContext($this->tenantId);
        $employmentId = $this->createEmployment();

        $this->expectException(QueryException::class);

        DB::table('compensation_assignments')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId,
            'employment_id' => $employmentId,
            'compensation_component_id' => $foreignComponentId,
            'status' => CompensationAssignment::STATUS_DRAFT,
            'amount' => '5000000.0000',
            'currency_code' => 'IDR',
            'effective_from' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_hard_delete_of_component_referenced_by_assignment_is_restricted(): void
    {
        $component = $this->createFixedComponent('BASE_SALARY');
        $employmentId = $this->createEmployment();

        CompensationAssignment::create([
            'employment_id' => $employmentId,
            'compensation_component_id' => $component->id,
            'status' => CompensationAssignment::STATUS_DRAFT,
            'amount' => '5000000.0000',
            'currency_code' => 'IDR',
            'effective_from' => '2026-01-01',
        ]);

        $this->expectException(QueryException::class);

        DB::table('compensation_components')
            ->where('id', $component->id)
            ->delete();
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
                'compensation-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createFixedComponent(string $code): CompensationComponent
    {
        return CompensationComponent::create([
            'code' => $code,
            'name' => 'Komponen Uji ' . $code,
            'category' => CompensationComponent::CATEGORY_BASE_PAY,
            'value_mode' => CompensationComponent::VALUE_MODE_FIXED_AMOUNT,
            'unit_code' => null,
            'periodicity' => 'MONTHLY',
        ]);
    }

    private function createMembership(): string
    {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Compensation Fixture Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $membershipId = UuidV7::generate();

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $membershipId;
    }

    private function createEmployment(): string
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
            'status' => Employment::STATUS_ACTIVE,
            'start_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employmentId;
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

    private function createApprovedAssignment(
        string $employmentId,
        string $componentId,
        string $membershipId,
        string $effectiveFrom,
        ?string $effectiveTo,
        ?string $employmentPositionAssignmentId = null,
    ): CompensationAssignment {
        return CompensationAssignment::create([
            'employment_id' => $employmentId,
            'compensation_component_id' => $componentId,
            'employment_position_assignment_id' => $employmentPositionAssignmentId,
            'status' => CompensationAssignment::STATUS_APPROVED,
            'amount' => '5000000.0000',
            'currency_code' => 'IDR',
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'approved_by_membership_id' => $membershipId,
            'approved_at' => now(),
        ]);
    }
}
