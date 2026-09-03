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
use Modules\HR\Models\EmploymentPositionAssignment;
use Modules\HR\Models\Position;
use Tests\TestCase;

final class EmploymentPositionAssignmentPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->createTenant('Position Assignment Tenant');
        $this->activateTenantContext($this->tenantId);
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_scoped_assignment_can_be_created_with_a_placement(): void
    {
        $employmentId = $this->createEmployment();
        $positionId = $this->createPosition();
        $placementId = $this->createPlacement($employmentId);

        $assignment = EmploymentPositionAssignment::create([
            'employment_id' => $employmentId,
            'position_id' => $positionId,
            'employment_placement_id' => $placementId,
            'effective_from' => '2026-01-01',
        ]);

        $this->assertSame($placementId, $assignment->employment_placement_id);
    }

    public function test_tenant_level_assignment_can_be_created_without_a_placement(): void
    {
        $employmentId = $this->createEmployment();
        $positionId = $this->createPosition();

        $assignment = EmploymentPositionAssignment::create([
            'employment_id' => $employmentId,
            'position_id' => $positionId,
            'employment_placement_id' => null,
            'effective_from' => '2026-01-01',
        ]);

        $this->assertNull($assignment->employment_placement_id);
    }

    public function test_database_rejects_duplicate_open_scoped_assignment(): void
    {
        $employmentId = $this->createEmployment();
        $positionId = $this->createPosition();
        $placementId = $this->createPlacement($employmentId);

        EmploymentPositionAssignment::create([
            'employment_id' => $employmentId,
            'position_id' => $positionId,
            'employment_placement_id' => $placementId,
            'effective_from' => '2026-01-01',
        ]);

        $this->expectException(QueryException::class);

        EmploymentPositionAssignment::create([
            'employment_id' => $employmentId,
            'position_id' => $positionId,
            'employment_placement_id' => $placementId,
            'effective_from' => '2026-02-01',
        ]);
    }

    public function test_database_rejects_duplicate_open_unscoped_assignment(): void
    {
        $employmentId = $this->createEmployment();
        $positionId = $this->createPosition();

        EmploymentPositionAssignment::create([
            'employment_id' => $employmentId,
            'position_id' => $positionId,
            'effective_from' => '2026-01-01',
        ]);

        $this->expectException(QueryException::class);

        EmploymentPositionAssignment::create([
            'employment_id' => $employmentId,
            'position_id' => $positionId,
            'effective_from' => '2026-02-01',
        ]);
    }

    /**
     * Membuktikan kedua guard (scoped vs unscoped) benar-benar independen:
     * Position yang sama boleh punya SATU assignment terbuka berjenis
     * tenant-level DAN SATU assignment terbuka berjenis scoped sekaligus,
     * karena keduanya ditegakkan oleh partial index yang berbeda.
     */
    public function test_scoped_and_unscoped_open_assignments_for_same_position_do_not_conflict(): void
    {
        $employmentId = $this->createEmployment();
        $positionId = $this->createPosition();
        $placementId = $this->createPlacement($employmentId);

        EmploymentPositionAssignment::create([
            'employment_id' => $employmentId,
            'position_id' => $positionId,
            'effective_from' => '2026-01-01',
        ]);

        $scoped = EmploymentPositionAssignment::create([
            'employment_id' => $employmentId,
            'position_id' => $positionId,
            'employment_placement_id' => $placementId,
            'effective_from' => '2026-01-01',
        ]);

        $this->assertSame($placementId, $scoped->employment_placement_id);
    }

    /**
     * INV-HR-009 — max satu open primary position per Employment.
     */
    public function test_database_rejects_second_open_primary_position(): void
    {
        $employmentId = $this->createEmployment();
        $firstPositionId = $this->createPosition();
        $secondPositionId = $this->createPosition();

        EmploymentPositionAssignment::create([
            'employment_id' => $employmentId,
            'position_id' => $firstPositionId,
            'effective_from' => '2026-01-01',
            'is_primary' => true,
        ]);

        $this->expectException(QueryException::class);

        EmploymentPositionAssignment::create([
            'employment_id' => $employmentId,
            'position_id' => $secondPositionId,
            'effective_from' => '2026-01-01',
            'is_primary' => true,
        ]);
    }

    public function test_check_constraint_rejects_effective_to_before_effective_from(): void
    {
        $employmentId = $this->createEmployment();
        $positionId = $this->createPosition();

        $this->expectException(QueryException::class);

        EmploymentPositionAssignment::create([
            'employment_id' => $employmentId,
            'position_id' => $positionId,
            'effective_from' => '2026-06-01',
            'effective_to' => '2026-01-01',
        ]);
    }

    /**
     * FK (employment_placement_id, employment_id, tenant_id) harus
     * menolak Placement yang sebenarnya milik Employment lain, walaupun
     * placement_id-nya valid.
     */
    public function test_composite_foreign_key_rejects_placement_belonging_to_another_employment(): void
    {
        $employmentId = $this->createEmployment();
        $otherEmploymentId = $this->createEmployment();
        $positionId = $this->createPosition();
        $placementFromOtherEmployment = $this->createPlacement($otherEmploymentId);

        $this->expectException(QueryException::class);

        EmploymentPositionAssignment::create([
            'employment_id' => $employmentId,
            'position_id' => $positionId,
            'employment_placement_id' => $placementFromOtherEmployment,
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_employment_position_assignments_relation_returns_owned_records(): void
    {
        $employmentId = $this->createEmployment();
        $positionId = $this->createPosition();

        EmploymentPositionAssignment::create([
            'employment_id' => $employmentId,
            'position_id' => $positionId,
            'effective_from' => '2026-01-01',
        ]);

        /** @var Employment $employment */
        $employment = Employment::query()->findOrFail($employmentId);

        $this->assertCount(1, $employment->positionAssignments);
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
                'position-assignment-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createEmployment(): string
    {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Position Assignment Fixture Person',
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

    private function createPosition(): string
    {
        return Position::create([
            'code' => 'POS-' . Str::upper(Str::random(6)),
            'name' => 'Posisi Uji',
            'is_active' => true,
        ])->id;
    }

    private function createPlacement(string $employmentId): string
    {
        $membershipId = DB::table('employees')
            ->join('employments', 'employees.id', '=', 'employments.employee_id')
            ->where('employments.id', $employmentId)
            ->value('employees.membership_id');

        $organizationId = UuidV7::generate();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'Position Assignment Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignmentId = UuidV7::generate();

        DB::table('organizational_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $membershipId,
            'organization_id' => $organizationId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $placementId = UuidV7::generate();

        DB::table('employment_placements')->insert([
            'id' => $placementId,
            'tenant_id' => $this->tenantId,
            'employment_id' => $employmentId,
            'organizational_assignment_id' => $assignmentId,
            'effective_from' => '2026-01-01',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $placementId;
    }
}
