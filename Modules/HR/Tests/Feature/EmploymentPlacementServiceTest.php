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
use Modules\HR\Exceptions\EmploymentLifecycleException;
use Modules\HR\Models\EmploymentType;
use Modules\HR\Services\EmploymentLifecycleService;
use Modules\HR\Services\EmploymentPlacementService;
use Tests\TestCase;

final class EmploymentPlacementServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmploymentPlacementService $service;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EmploymentPlacementService();
        $this->tenantId = $this->createTenant('Placement Service Tenant');
        $this->activateTenantContext($this->tenantId);
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_create_placement_succeeds_for_active_employment_and_assignment(): void
    {
        [$employmentId, $membershipId] = $this->createActiveEmployment();
        $assignmentId = $this->createAssignment($membershipId);

        $placement = $this->service->createPlacement(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'organizational_assignment_id' => $assignmentId,
                'effective_from' => '2026-01-01',
                'is_primary' => true,
            ],
        );

        $this->assertTrue($placement->is_primary);
        $this->assertSame($assignmentId, $placement->organizational_assignment_id);
    }

    public function test_create_placement_rejects_non_active_employment(): void
    {
        [$plannedEmploymentId, $membershipId] = $this->createPlannedEmployment();
        $assignmentId = $this->createAssignment($membershipId);

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/must be ACTIVE to create a Placement/');

        $this->service->createPlacement(
            tenantId: $this->tenantId,
            employmentId: $plannedEmploymentId,
            data: [
                'organizational_assignment_id' => $assignmentId,
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_placement_rejects_effective_from_before_employment_start_date(): void
    {
        [$employmentId, $membershipId] = $this->createActiveEmployment(startDate: '2026-03-01');
        $assignmentId = $this->createAssignment($membershipId);

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/cannot be earlier than Employment start_date/');

        $this->service->createPlacement(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'organizational_assignment_id' => $assignmentId,
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_placement_rejects_future_effective_from(): void
    {
        [$employmentId, $membershipId] = $this->createActiveEmployment();
        $assignmentId = $this->createAssignment($membershipId);

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/cannot be in the future/');

        $this->service->createPlacement(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'organizational_assignment_id' => $assignmentId,
                'effective_from' => now()->addYear()->toDateString(),
            ],
        );
    }

    public function test_create_placement_rejects_unknown_assignment_id(): void
    {
        [$employmentId] = $this->createActiveEmployment();

        $this->expectException(ModelNotFoundException::class);

        $this->service->createPlacement(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'organizational_assignment_id' => UuidV7::generate(),
                'effective_from' => '2026-01-01',
            ],
        );
    }

    /**
     * INV-HR-005.
     */
    public function test_create_placement_rejects_inactive_assignment(): void
    {
        [$employmentId, $membershipId] = $this->createActiveEmployment();
        $assignmentId = $this->createAssignment($membershipId, status: 'INACTIVE');

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/INV-HR-005/');

        $this->service->createPlacement(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'organizational_assignment_id' => $assignmentId,
                'effective_from' => '2026-01-01',
            ],
        );
    }

    /**
     * INV-HR-004.
     */
    public function test_create_placement_rejects_assignment_with_different_membership(): void
    {
        [$employmentId] = $this->createActiveEmployment();
        $unrelatedMembershipId = $this->createMembership();
        $assignmentId = $this->createAssignment($unrelatedMembershipId);

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/INV-HR-004/');

        $this->service->createPlacement(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'organizational_assignment_id' => $assignmentId,
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_placement_rejects_duplicate_open_placement(): void
    {
        [$employmentId, $membershipId] = $this->createActiveEmployment();
        $assignmentId = $this->createAssignment($membershipId);

        $this->service->createPlacement(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'organizational_assignment_id' => $assignmentId,
                'effective_from' => '2026-01-01',
            ],
        );

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/already has an open Placement/');

        $this->service->createPlacement(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'organizational_assignment_id' => $assignmentId,
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_placement_rejects_second_open_primary(): void
    {
        [$employmentId, $membershipId] = $this->createActiveEmployment();
        $firstAssignmentId = $this->createAssignment($membershipId);
        $secondAssignmentId = $this->createAssignment($membershipId);

        $this->service->createPlacement(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'organizational_assignment_id' => $firstAssignmentId,
                'effective_from' => '2026-01-01',
                'is_primary' => true,
            ],
        );

        $this->expectException(EmploymentLifecycleException::class);
        $this->expectExceptionMessageMatches('/INV-HR-009/');

        $this->service->createPlacement(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'organizational_assignment_id' => $secondAssignmentId,
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
                'placement-svc-%s',
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
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Placement Service Fixture Person',
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

        return $membershipId;
    }

    /**
     * @return array{0: string, 1: string} [employmentId, membershipId]
     */
    private function createActiveEmployment(string $startDate = '2026-01-01'): array
    {
        [$employeeId, $membershipId] = $this->createEmployee();
        $employmentTypeId = EmploymentType::create([
            'code' => 'TIPE-' . Str::upper(Str::random(6)),
            'name' => 'Tipe Uji Placement',
            'is_active' => true,
        ])->id;

        $lifecycleService = new EmploymentLifecycleService();

        $planned = $lifecycleService->createPlanned(
            tenantId: $this->tenantId,
            employeeId: $employeeId,
            data: [
                'employment_type_id' => $employmentTypeId,
                'start_date' => $startDate,
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

        return [$employeeId, $membershipId];
    }

    private function createAssignment(
        string $membershipId,
        string $status = 'ACTIVE',
    ): string {
        $organizationId = UuidV7::generate();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'Placement Service Fixture Organization',
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
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $assignmentId;
    }
}
