<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Exceptions\EmploymentLifecycleException;
use Modules\HR\Models\EmploymentType;
use Modules\HR\Models\Position;
use Modules\HR\Services\EmploymentLifecycleService;
use Modules\HR\Services\EmploymentPlacementService;
use Modules\HR\Services\EmploymentPositionAssignmentService;
use Tests\TestCase;

final class EmploymentPositionAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmploymentPositionAssignmentService $service;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EmploymentPositionAssignmentService();
        $this->tenantId = $this->createTenant('Position Assignment Service Tenant');
        $this->activateTenantContext($this->tenantId);
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_create_assignment_succeeds_without_placement(): void
    {
        [$employmentId] = $this->createActiveEmployment();
        $positionId = $this->createPosition();

        $assignment = $this->service->createAssignment(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'position_id' => $positionId,
                'effective_from' => '2026-01-01',
            ],
        );

        $this->assertNull($assignment->employment_placement_id);
    }

    public function test_create_assignment_succeeds_scoped_to_open_placement(): void
    {
        [$employmentId, $membershipId] = $this->createActiveEmployment();
        $positionId = $this->createPosition();
        $placementId = $this->createOpenPlacement($employmentId, $membershipId);

        $assignment = $this->service->createAssignment(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'position_id' => $positionId,
                'employment_placement_id' => $placementId,
                'effective_from' => '2026-01-01',
            ],
        );

        $this->assertSame($placementId, $assignment->employment_placement_id);
    }

    public function test_create_assignment_rejects_non_active_employment(): void
    {
        [$plannedEmploymentId] = $this->createPlannedEmployment();
        $positionId = $this->createPosition();

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/must be ACTIVE to create a Position Assignment/');

        $this->service->createAssignment(
            tenantId: $this->tenantId,
            employmentId: $plannedEmploymentId,
            data: [
                'position_id' => $positionId,
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_assignment_rejects_inactive_position(): void
    {
        [$employmentId] = $this->createActiveEmployment();
        $inactivePositionId = $this->createPosition(isActive: false);

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/active catalog entry/');

        $this->service->createAssignment(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'position_id' => $inactivePositionId,
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_assignment_rejects_placement_not_owned_by_employment(): void
    {
        [$employmentId] = $this->createActiveEmployment();
        [$otherEmploymentId, $otherMembershipId] = $this->createActiveEmployment();
        $positionId = $this->createPosition();
        $placementFromOtherEmployment = $this->createOpenPlacement($otherEmploymentId, $otherMembershipId);

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/not an open Placement owned by Employment/');

        $this->service->createAssignment(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'position_id' => $positionId,
                'employment_placement_id' => $placementFromOtherEmployment,
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_assignment_rejects_effective_from_before_placement_effective_from(): void
    {
        [$employmentId, $membershipId] = $this->createActiveEmployment();
        $positionId = $this->createPosition();
        $placementId = $this->createOpenPlacement($employmentId, $membershipId, effectiveFrom: '2026-06-01');

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/earlier than the referenced Placement effective_from/');

        $this->service->createAssignment(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'position_id' => $positionId,
                'employment_placement_id' => $placementId,
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_assignment_rejects_duplicate_open_unscoped_assignment(): void
    {
        [$employmentId] = $this->createActiveEmployment();
        $positionId = $this->createPosition();

        $this->service->createAssignment(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'position_id' => $positionId,
                'effective_from' => '2026-01-01',
            ],
        );

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/already has an open Position Assignment/');

        $this->service->createAssignment(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'position_id' => $positionId,
                'effective_from' => '2026-02-01',
            ],
        );
    }

    public function test_create_assignment_rejects_second_open_primary(): void
    {
        [$employmentId] = $this->createActiveEmployment();
        $firstPositionId = $this->createPosition();
        $secondPositionId = $this->createPosition();

        $this->service->createAssignment(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'position_id' => $firstPositionId,
                'effective_from' => '2026-01-01',
                'is_primary' => true,
            ],
        );

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/INV-HR-009/');

        $this->service->createAssignment(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'position_id' => $secondPositionId,
                'effective_from' => '2026-01-01',
                'is_primary' => true,
            ],
        );
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
                'position-assignment-svc-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    /**
     * @return array{0: string, 1: string} [employmentId, membershipId]
     */
    private function createActiveEmployment(): array
    {
        [$employeeId, $membershipId] = $this->createEmployee();
        $employmentTypeId = EmploymentType::create([
            'code' => 'TIPE-' . Str::upper(Str::random(6)),
            'name' => 'Tipe Uji Posisi',
            'is_active' => true,
        ])->id;

        $lifecycleService = new EmploymentLifecycleService();

        $planned = $lifecycleService->createPlanned(
            tenantId: $this->tenantId,
            employeeId: $employeeId,
            data: [
                'employment_type_id' => $employmentTypeId,
                'start_date' => '2026-01-01',
            ],
        );

        $active = $lifecycleService->activate($this->tenantId, $planned->id);

        return [$active->id, $membershipId];
    }

    /**
     * @return array{0: string, 1: string} [employmentId, membershipId]
     */
    private function createPlannedEmployment(): array
    {
        [$employeeId, $membershipId] = $this->createEmployee();

        $planned = (new EmploymentLifecycleService())->createPlanned(
            tenantId: $this->tenantId,
            employeeId: $employeeId,
            data: ['start_date' => '2026-01-01'],
        );

        return [$planned->id, $membershipId];
    }

    /**
     * @return array{0: string, 1: string} [employeeId, membershipId]
     */
    private function createEmployee(): array
    {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Position Assignment Service Fixture Person',
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

        return [$employeeId, $membershipId];
    }

    private function createPosition(bool $isActive = true): string
    {
        return Position::create([
            'code' => 'POS-' . Str::upper(Str::random(6)),
            'name' => 'Posisi Uji Layanan',
            'is_active' => $isActive,
        ])->id;
    }

    private function createOpenPlacement(
        string $employmentId,
        string $membershipId,
        string $effectiveFrom = '2026-01-01',
    ): string {
        return (new EmploymentPlacementService())->createPlacement(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'organizational_assignment_id' => $this->createAssignment($membershipId),
                'effective_from' => $effectiveFrom,
            ],
        )->id;
    }

    private function createAssignment(string $membershipId): string
    {
        $organizationId = UuidV7::generate();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'Position Assignment Service Fixture Organization',
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

        return $assignmentId;
    }
}
