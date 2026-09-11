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
use Modules\HR\Models\CompensationAdjustment;
use Modules\HR\Models\CompensationComponent;
use Modules\HR\Models\Employment;
use Tests\TestCase;

final class CompensationAdjustmentPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $employmentId;

    private string $requesterMembershipId;

    private string $approverMembershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->createTenant('Compensation Adjustment Tenant');
        $this->activateTenantContext($this->tenantId);

        $this->employmentId = $this->createEmployment();
        $this->requesterMembershipId = $this->createMembership();
        $this->approverMembershipId = $this->createMembership();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_adjustment_can_be_created_as_draft(): void
    {
        $adjustment = $this->createAdjustment();

        $this->assertSame(CompensationAdjustment::STATUS_DRAFT, $adjustment->status);
        $this->assertNull($adjustment->approved_by_membership_id);
        $this->assertNull($adjustment->compensation_component_id);
    }

    public function test_adjustment_can_reference_optional_component(): void
    {
        $componentId = $this->createComponent()->id;

        $adjustment = $this->createAdjustment([
            'compensation_component_id' => $componentId,
        ]);

        $this->assertSame($componentId, $adjustment->compensation_component_id);
    }

    public function test_database_rejects_invalid_adjustment_type(): void
    {
        $this->expectException(QueryException::class);

        $this->createAdjustment(['adjustment_type' => 'NOT_A_REAL_TYPE']);
    }

    public function test_database_rejects_invalid_status(): void
    {
        $this->expectException(QueryException::class);

        $this->createAdjustment(['status' => 'NOT_A_REAL_STATUS']);
    }

    public function test_database_rejects_zero_amount(): void
    {
        $this->expectException(QueryException::class);

        $this->createAdjustment(['amount' => '0.0000']);
    }

    public function test_database_rejects_negative_amount(): void
    {
        $this->expectException(QueryException::class);

        $this->createAdjustment(['amount' => '-500000.0000']);
    }

    public function test_database_rejects_invalid_currency_code(): void
    {
        $this->expectException(QueryException::class);

        $this->createAdjustment(['currency_code' => 'idr']);
    }

    public function test_database_rejects_target_period_end_before_start(): void
    {
        $this->expectException(QueryException::class);

        $this->createAdjustment([
            'target_period_start' => '2026-06-01',
            'target_period_end' => '2026-01-01',
        ]);
    }

    public function test_database_rejects_duplicate_idempotency_key_within_tenant(): void
    {
        $this->createAdjustment(['idempotency_key' => 'ADJ-DUPLICATE-KEY']);

        $this->expectException(QueryException::class);

        $this->createAdjustment(['idempotency_key' => 'ADJ-DUPLICATE-KEY']);
    }

    public function test_same_idempotency_key_is_allowed_across_different_tenants(): void
    {
        $this->createAdjustment(['idempotency_key' => 'ADJ-SHARED-KEY']);

        $otherTenantId = $this->createTenant('Compensation Adjustment Other Tenant');
        $this->activateTenantContext($otherTenantId);

        $otherEmploymentId = $this->createEmployment();
        $otherRequesterId = $this->createMembership();

        $adjustment = CompensationAdjustment::create([
            'employment_id' => $otherEmploymentId,
            'adjustment_type' => CompensationAdjustment::TYPE_ONE_TIME_EARNING,
            'amount' => '1000000.0000',
            'currency_code' => 'IDR',
            'target_period_start' => '2026-01-01',
            'target_period_end' => '2026-01-31',
            'status' => CompensationAdjustment::STATUS_DRAFT,
            'reason' => 'Uji lintas tenant.',
            'requested_by_membership_id' => $otherRequesterId,
            'idempotency_key' => 'ADJ-SHARED-KEY',
        ]);

        $this->assertNotNull($adjustment->id);
    }

    public function test_database_rejects_maker_checker_violation(): void
    {
        $this->expectException(QueryException::class);

        $this->createAdjustment([
            'status' => CompensationAdjustment::STATUS_APPROVED,
            'approved_by_membership_id' => $this->requesterMembershipId,
            'approved_at' => now(),
        ]);
    }

    public function test_adjustment_can_be_approved_by_different_membership(): void
    {
        $adjustment = $this->createAdjustment([
            'status' => CompensationAdjustment::STATUS_APPROVED,
            'approved_by_membership_id' => $this->approverMembershipId,
            'approved_at' => now(),
        ]);

        $this->assertSame(CompensationAdjustment::STATUS_APPROVED, $adjustment->status);
        $this->assertSame($this->approverMembershipId, $adjustment->approved_by_membership_id);
    }

    public function test_database_rejects_approved_status_without_approval_fields(): void
    {
        $this->expectException(QueryException::class);

        $this->createAdjustment(['status' => CompensationAdjustment::STATUS_APPROVED]);
    }

    public function test_database_rejects_draft_status_with_approval_fields(): void
    {
        $this->expectException(QueryException::class);

        $this->createAdjustment([
            'status' => CompensationAdjustment::STATUS_DRAFT,
            'approved_by_membership_id' => $this->approverMembershipId,
            'approved_at' => now(),
        ]);
    }

    public function test_database_rejects_cancelled_status_with_approval_fields(): void
    {
        $this->expectException(QueryException::class);

        $this->createAdjustment([
            'status' => CompensationAdjustment::STATUS_CANCELLED,
            'approved_by_membership_id' => $this->approverMembershipId,
            'approved_at' => now(),
        ]);
    }

    public function test_composite_foreign_key_rejects_component_from_another_tenant(): void
    {
        $otherTenantId = $this->createTenant('Compensation Adjustment Foreign Tenant');
        $this->activateTenantContext($otherTenantId);
        $foreignComponentId = $this->createComponent()->id;

        $this->activateTenantContext($this->tenantId);

        $this->expectException(QueryException::class);

        $this->createAdjustment(['compensation_component_id' => $foreignComponentId]);
    }

    public function test_hard_delete_of_component_referenced_by_adjustment_is_restricted(): void
    {
        $component = $this->createComponent();

        $this->createAdjustment(['compensation_component_id' => $component->id]);

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
                'compensation-adjustment-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createMembership(): string
    {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Compensation Adjustment Fixture Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $membershipId = UuidV7::generate();

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => app(TenantContextInterface::class)->getCurrentTenantId(),
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
        $activeTenantId = app(TenantContextInterface::class)->getCurrentTenantId();

        DB::table('employees')->insert([
            'id' => $employeeId,
            'tenant_id' => $activeTenantId,
            'membership_id' => $membershipId,
            'nip' => sprintf('NIP-%s', Str::upper(Str::random(8))),
            'jabatan' => 'GURU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employmentId = UuidV7::generate();

        DB::table('employments')->insert([
            'id' => $employmentId,
            'tenant_id' => $activeTenantId,
            'employee_id' => $employeeId,
            'status' => Employment::STATUS_ACTIVE,
            'start_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employmentId;
    }

    private function createComponent(): CompensationComponent
    {
        return CompensationComponent::create([
            'code' => 'COMP-'.Str::upper(Str::random(6)),
            'name' => 'Komponen Uji Adjustment',
            'category' => CompensationComponent::CATEGORY_OTHER_EARNING_INPUT,
            'value_mode' => CompensationComponent::VALUE_MODE_FIXED_AMOUNT,
            'unit_code' => null,
            'periodicity' => 'ONE_TIME',
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAdjustment(array $overrides = []): CompensationAdjustment
    {
        return CompensationAdjustment::create(array_merge([
            'employment_id' => $this->employmentId,
            'adjustment_type' => CompensationAdjustment::TYPE_ONE_TIME_EARNING,
            'amount' => '1000000.0000',
            'currency_code' => 'IDR',
            'target_period_start' => '2026-01-01',
            'target_period_end' => '2026-01-31',
            'status' => CompensationAdjustment::STATUS_DRAFT,
            'reason' => 'Uji coba penyesuaian kompensasi.',
            'requested_by_membership_id' => $this->requesterMembershipId,
            'idempotency_key' => 'ADJ-'.Str::upper(Str::random(12)),
        ], $overrides));
    }
}
