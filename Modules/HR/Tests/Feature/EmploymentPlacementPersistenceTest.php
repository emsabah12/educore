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
use Modules\HR\Models\Employment;
use Modules\HR\Models\EmploymentPlacement;
use Tests\TestCase;

final class EmploymentPlacementPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantAId;
    private string $tenantBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantAId = $this->createTenant('Placement Tenant A');
        $this->tenantBId = $this->createTenant('Placement Tenant B');
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_placement_can_be_created_for_open_organizational_assignment(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $employmentId = $this->createEmployment($this->tenantAId);
        $assignmentId = $this->createOrganizationalAssignment($this->tenantAId);

        $placement = EmploymentPlacement::create([
            'employment_id' => $employmentId,
            'organizational_assignment_id' => $assignmentId,
            'effective_from' => '2026-01-01',
            'is_primary' => true,
        ]);

        $this->assertTrue(Str::isUuid($placement->id));
        $this->assertTrue($placement->is_primary);
        $this->assertNull($placement->effective_to);
    }

    /**
     * §9.2 langkah 8 — "reject duplicate open placement".
     */
    public function test_database_rejects_duplicate_open_placement_for_same_assignment(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $employmentId = $this->createEmployment($this->tenantAId);
        $assignmentId = $this->createOrganizationalAssignment($this->tenantAId);

        EmploymentPlacement::create([
            'employment_id' => $employmentId,
            'organizational_assignment_id' => $assignmentId,
            'effective_from' => '2026-01-01',
        ]);

        $this->expectException(QueryException::class);

        EmploymentPlacement::create([
            'employment_id' => $employmentId,
            'organizational_assignment_id' => $assignmentId,
            'effective_from' => '2026-02-01',
        ]);
    }

    public function test_closed_placements_do_not_count_toward_open_assignment_guard(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $employmentId = $this->createEmployment($this->tenantAId);
        $assignmentId = $this->createOrganizationalAssignment($this->tenantAId);

        EmploymentPlacement::create([
            'employment_id' => $employmentId,
            'organizational_assignment_id' => $assignmentId,
            'effective_from' => '2020-01-01',
            'effective_to' => '2022-12-31',
        ]);

        $reopened = EmploymentPlacement::create([
            'employment_id' => $employmentId,
            'organizational_assignment_id' => $assignmentId,
            'effective_from' => '2023-01-01',
        ]);

        $this->assertNull($reopened->effective_to);
        $this->assertSame(
            2,
            EmploymentPlacement::query()
                ->where('employment_id', $employmentId)
                ->count(),
        );
    }

    /**
     * INV-HR-009 — "never more than one open primary placement".
     */
    public function test_database_rejects_second_open_primary_placement(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $employmentId = $this->createEmployment($this->tenantAId);
        $firstAssignmentId = $this->createOrganizationalAssignment($this->tenantAId);
        $secondAssignmentId = $this->createOrganizationalAssignment($this->tenantAId);

        EmploymentPlacement::create([
            'employment_id' => $employmentId,
            'organizational_assignment_id' => $firstAssignmentId,
            'effective_from' => '2026-01-01',
            'is_primary' => true,
        ]);

        $this->expectException(QueryException::class);

        EmploymentPlacement::create([
            'employment_id' => $employmentId,
            'organizational_assignment_id' => $secondAssignmentId,
            'effective_from' => '2026-01-01',
            'is_primary' => true,
        ]);
    }

    public function test_check_constraint_rejects_effective_to_before_effective_from(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $employmentId = $this->createEmployment($this->tenantAId);
        $assignmentId = $this->createOrganizationalAssignment($this->tenantAId);

        $this->expectException(QueryException::class);

        EmploymentPlacement::create([
            'employment_id' => $employmentId,
            'organizational_assignment_id' => $assignmentId,
            'effective_from' => '2026-06-01',
            'effective_to' => '2026-01-01',
        ]);
    }

    public function test_composite_foreign_key_rejects_assignment_from_another_tenant(): void
    {
        $assignmentFromTenantB = $this->createOrganizationalAssignment($this->tenantBId);

        $this->activateTenantContext($this->tenantAId);
        $employmentId = $this->createEmployment($this->tenantAId);

        $this->expectException(QueryException::class);

        EmploymentPlacement::create([
            'employment_id' => $employmentId,
            'organizational_assignment_id' => $assignmentFromTenantB,
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_employment_placements_relation_returns_owned_records(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $employmentId = $this->createEmployment($this->tenantAId);
        $assignmentId = $this->createOrganizationalAssignment($this->tenantAId);

        EmploymentPlacement::create([
            'employment_id' => $employmentId,
            'organizational_assignment_id' => $assignmentId,
            'effective_from' => '2026-01-01',
        ]);

        /** @var Employment $employment */
        $employment = Employment::query()->findOrFail($employmentId);

        $this->assertCount(1, $employment->placements);
    }

    private function activateTenantContext(string $tenantId): void
    {
        /** @var Tenant $tenant */
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
                'placement-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createEmployment(string $tenantId): string
    {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Placement Fixture Person',
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

        $employeeId = UuidV7::generate();

        DB::table('employees')->insert([
            'id' => $employeeId,
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'nip' => sprintf('NIP-%s', Str::upper(Str::random(8))),
            'jabatan' => 'GURU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employmentId = UuidV7::generate();

        DB::table('employments')->insert([
            'id' => $employmentId,
            'tenant_id' => $tenantId,
            'employee_id' => $employeeId,
            'status' => Employment::STATUS_ACTIVE,
            'start_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employmentId;
    }

    private function createOrganizationalAssignment(string $tenantId): string
    {
        $membershipPersonId = UuidV7::generate();
        $assignmentMembershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $membershipPersonId,
            'name' => 'Assignment Fixture Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $assignmentMembershipId,
            'person_id' => $membershipPersonId,
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $organizationId = UuidV7::generate();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $tenantId,
            'name' => 'Placement Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignmentId = UuidV7::generate();

        DB::table('organizational_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $tenantId,
            'membership_id' => $assignmentMembershipId,
            'organization_id' => $organizationId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $assignmentId;
    }
}
