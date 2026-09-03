<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Organization\Models\OrganizationalAssignment;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Exceptions\EmploymentLifecycleException;
use Modules\HR\Models\Employment;
use Modules\HR\Models\EmploymentPlacement;
use Modules\HR\Models\EmploymentPositionAssignment;
use Modules\HR\Models\EmploymentType;
use Modules\HR\Models\Position;
use Modules\HR\Services\EmploymentLifecycleService;
use Modules\HR\Services\EmploymentPlacementService;
use Modules\HR\Services\EmploymentPositionAssignmentService;
use Tests\TestCase;

final class EmploymentEndTest extends TestCase
{
    use RefreshDatabase;

    private EmploymentLifecycleService $service;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EmploymentLifecycleService();
        $this->tenantId = $this->createTenant('End Employment Tenant');
        $this->activateTenantContext($this->tenantId);
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_end_transitions_active_employment_to_ended_with_end_date(): void
    {
        [$employmentId] = $this->createActiveEmployment();

        $ended = $this->service->end($this->tenantId, $employmentId, '2026-06-01');

        $this->assertSame(Employment::STATUS_ENDED, $ended->status);
        $this->assertSame('2026-06-01', $ended->end_date->toDateString());
    }

    /**
     * INV-HR-008 — Employment end closes open HR children, atomically.
     */
    public function test_end_closes_open_placement_and_position_assignment(): void
    {
        [$employmentId, $membershipId] = $this->createActiveEmployment();
        $placementId = $this->createOpenPlacement($employmentId, $membershipId);
        $positionAssignmentId = $this->createOpenPositionAssignment($employmentId, $placementId);

        $this->service->end($this->tenantId, $employmentId, '2026-06-01');

        /** @var EmploymentPlacement $placement */
        $placement = EmploymentPlacement::query()->findOrFail($placementId);
        /** @var EmploymentPositionAssignment $positionAssignment */
        $positionAssignment = EmploymentPositionAssignment::query()->findOrFail($positionAssignmentId);

        $this->assertSame('2026-06-01', $placement->effective_to->toDateString());
        $this->assertSame('2026-06-01', $positionAssignment->effective_to->toDateString());
    }

    /**
     * INV-HR-006 — closing HR history does not deactivate the Core
     * OrganizationalAssignment; it may still be used by other domains.
     */
    public function test_end_does_not_deactivate_core_organizational_assignment(): void
    {
        [$employmentId, $membershipId] = $this->createActiveEmployment();
        $placementId = $this->createOpenPlacement($employmentId, $membershipId);

        /** @var EmploymentPlacement $placement */
        $placement = EmploymentPlacement::query()->findOrFail($placementId);
        $assignmentId = $placement->organizational_assignment_id;

        $this->service->end($this->tenantId, $employmentId, '2026-06-01');

        /** @var OrganizationalAssignment $assignment */
        $assignment = OrganizationalAssignment::query()
            ->withoutGlobalScope('tenant')
            ->findOrFail($assignmentId);

        $this->assertSame(OrganizationalAssignment::STATUS_ACTIVE, $assignment->status);
    }

    public function test_end_rejects_non_active_employment(): void
    {
        [$plannedEmploymentId] = $this->createPlannedEmployment();

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/Only ACTIVE employment may be ended/');

        $this->service->end($this->tenantId, $plannedEmploymentId, '2026-06-01');
    }

    public function test_end_rejects_end_date_before_start_date(): void
    {
        [$employmentId] = $this->createActiveEmployment(startDate: '2026-03-01');

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/cannot be earlier than Employment start_date/');

        $this->service->end($this->tenantId, $employmentId, '2026-01-01');
    }

    public function test_end_rejects_future_end_date(): void
    {
        [$employmentId] = $this->createActiveEmployment();

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/cannot be in the future/');

        $this->service->end($this->tenantId, $employmentId, now()->addYear()->toDateString());
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
                'end-employment-%s',
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
    private function createActiveEmployment(string $startDate = '2026-01-01'): array
    {
        [$employeeId, $membershipId] = $this->createEmployee();
        $employmentTypeId = EmploymentType::create([
            'code' => 'TIPE-' . Str::upper(Str::random(6)),
            'name' => 'Tipe Uji End Employment',
            'is_active' => true,
        ])->id;

        $planned = $this->service->createPlanned(
            tenantId: $this->tenantId,
            employeeId: $employeeId,
            data: [
                'employment_type_id' => $employmentTypeId,
                'start_date' => $startDate,
            ],
        );

        $active = $this->service->activate($this->tenantId, $planned->id);

        return [$active->id, $membershipId];
    }

    /**
     * @return array{0: string, 1: string} [employmentId, membershipId]
     */
    private function createPlannedEmployment(): array
    {
        [$employeeId, $membershipId] = $this->createEmployee();

        $planned = $this->service->createPlanned(
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
            'name' => 'End Employment Fixture Person',
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

    private function createOpenPlacement(string $employmentId, string $membershipId): string
    {
        $organizationId = UuidV7::generate();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'End Employment Fixture Organization',
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

        return (new EmploymentPlacementService())->createPlacement(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'organizational_assignment_id' => $assignmentId,
                'effective_from' => '2026-01-01',
            ],
        )->id;
    }

    private function createOpenPositionAssignment(
        string $employmentId,
        string $placementId,
    ): string {
        $positionId = Position::create([
            'code' => 'POS-' . Str::upper(Str::random(6)),
            'name' => 'Posisi Uji End Employment',
            'is_active' => true,
        ])->id;

        return (new EmploymentPositionAssignmentService())->createAssignment(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'position_id' => $positionId,
                'employment_placement_id' => $placementId,
                'effective_from' => '2026-01-01',
            ],
        )->id;
    }
}
