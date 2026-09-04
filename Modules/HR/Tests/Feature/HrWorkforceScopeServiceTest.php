<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Organization\Context\OrganizationalContext;
use Modules\Core\Organization\Contracts\OrganizationalContextInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\HR\Exceptions\HrResourceScopeException;
use Modules\HR\Services\HrWorkforceScopeService;
use Tests\TestCase;

final class HrWorkforceScopeServiceTest extends TestCase
{
    use RefreshDatabase;

    private HrWorkforceScopeService $service;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new HrWorkforceScopeService(
            app(OrganizationalContextInterface::class),
        );

        $this->tenantId = $this->createTenant();
    }

    protected function tearDown(): void
    {
        app(OrganizationalContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_returns_false_when_no_organizational_context_is_set(): void
    {
        $employeeId = $this->createEmployeeWithOpenPlacement(
            organizationId: $this->createOrganization(),
        )['employeeId'];

        $this->assertFalse(
            $this->service->isEmployeeVisibleInCurrentContext(
                $this->tenantId,
                $employeeId,
            ),
        );
    }

    public function test_employee_is_visible_when_organization_matches_and_context_is_org_level(): void
    {
        $organizationId = $this->createOrganization();
        $employeeId = $this->createEmployeeWithOpenPlacement(
            organizationId: $organizationId,
        )['employeeId'];

        $this->setOrganizationalContext(
            organizationId: $organizationId,
            organizationUnitId: null,
        );

        $this->assertTrue(
            $this->service->isEmployeeVisibleInCurrentContext(
                $this->tenantId,
                $employeeId,
            ),
        );
    }

    public function test_employee_is_visible_in_org_context_regardless_of_which_unit_placement_belongs_to(): void
    {
        $organizationId = $this->createOrganization();
        $unitId = $this->createOrganizationUnit($organizationId);
        $employeeId = $this->createEmployeeWithOpenPlacement(
            organizationId: $organizationId,
            organizationUnitId: $unitId,
        )['employeeId'];

        // Context org-level (unit NULL) — HR-002 §12.2: unit manapun di
        // dalam organisasi yang sama tetap terlihat.
        $this->setOrganizationalContext(
            organizationId: $organizationId,
            organizationUnitId: null,
        );

        $this->assertTrue(
            $this->service->isEmployeeVisibleInCurrentContext(
                $this->tenantId,
                $employeeId,
            ),
        );
    }

    public function test_employee_is_visible_for_exact_matching_unit(): void
    {
        $organizationId = $this->createOrganization();
        $unitId = $this->createOrganizationUnit($organizationId);
        $employeeId = $this->createEmployeeWithOpenPlacement(
            organizationId: $organizationId,
            organizationUnitId: $unitId,
        )['employeeId'];

        $this->setOrganizationalContext(
            organizationId: $organizationId,
            organizationUnitId: $unitId,
        );

        $this->assertTrue(
            $this->service->isEmployeeVisibleInCurrentContext(
                $this->tenantId,
                $employeeId,
            ),
        );
    }

    public function test_employee_is_not_visible_for_sibling_unit(): void
    {
        $organizationId = $this->createOrganization();
        $unitAId = $this->createOrganizationUnit($organizationId);
        $unitBId = $this->createOrganizationUnit($organizationId);

        $employeeId = $this->createEmployeeWithOpenPlacement(
            organizationId: $organizationId,
            organizationUnitId: $unitAId,
        )['employeeId'];

        // Context di-scope ke Unit B (sibling), Employee ada di Unit A.
        $this->setOrganizationalContext(
            organizationId: $organizationId,
            organizationUnitId: $unitBId,
        );

        $this->assertFalse(
            $this->service->isEmployeeVisibleInCurrentContext(
                $this->tenantId,
                $employeeId,
            ),
        );
    }

    public function test_employee_is_not_visible_for_different_organization(): void
    {
        $employeeId = $this->createEmployeeWithOpenPlacement(
            organizationId: $this->createOrganization(),
        )['employeeId'];

        $this->setOrganizationalContext(
            organizationId: $this->createOrganization(),
            organizationUnitId: null,
        );

        $this->assertFalse(
            $this->service->isEmployeeVisibleInCurrentContext(
                $this->tenantId,
                $employeeId,
            ),
        );
    }

    public function test_employee_with_no_open_placement_is_not_visible(): void
    {
        $employeeId = $this->createEmployeeWithoutPlacement();
        $organizationId = $this->createOrganization();

        $this->setOrganizationalContext(
            organizationId: $organizationId,
            organizationUnitId: null,
        );

        $this->assertFalse(
            $this->service->isEmployeeVisibleInCurrentContext(
                $this->tenantId,
                $employeeId,
            ),
        );
    }

    public function test_employee_with_closed_placement_is_not_visible(): void
    {
        $organizationId = $this->createOrganization();
        $fixture = $this->createEmployeeWithOpenPlacement(
            organizationId: $organizationId,
        );

        DB::table('employment_placements')
            ->where('id', $fixture['placementId'])
            ->update(['effective_to' => '2026-06-01']);

        $this->setOrganizationalContext(
            organizationId: $organizationId,
            organizationUnitId: null,
        );

        $this->assertFalse(
            $this->service->isEmployeeVisibleInCurrentContext(
                $this->tenantId,
                $fixture['employeeId'],
            ),
        );
    }

    public function test_employee_with_inactive_assignment_is_not_visible(): void
    {
        $organizationId = $this->createOrganization();
        $fixture = $this->createEmployeeWithOpenPlacement(
            organizationId: $organizationId,
        );

        DB::table('organizational_assignments')
            ->where('id', $fixture['assignmentId'])
            ->update(['status' => 'INACTIVE']);

        $this->setOrganizationalContext(
            organizationId: $organizationId,
            organizationUnitId: null,
        );

        $this->assertFalse(
            $this->service->isEmployeeVisibleInCurrentContext(
                $this->tenantId,
                $fixture['employeeId'],
            ),
        );
    }

    public function test_assert_throws_when_employee_is_not_visible(): void
    {
        $employeeId = $this->createEmployeeWithoutPlacement();

        $this->setOrganizationalContext(
            organizationId: $this->createOrganization(),
            organizationUnitId: null,
        );

        $this->expectException(HrResourceScopeException::class);

        $this->service->assertEmployeeVisibleInCurrentContext(
            $this->tenantId,
            $employeeId,
        );
    }

    public function test_visible_employees_query_returns_only_employees_in_matching_organization(): void
    {
        $organizationId = $this->createOrganization();
        $visibleEmployeeId = $this->createEmployeeWithOpenPlacement(
            organizationId: $organizationId,
        )['employeeId'];

        $otherOrganizationId = $this->createOrganization();
        $this->createEmployeeWithOpenPlacement(
            organizationId: $otherOrganizationId,
        );

        $this->setOrganizationalContext(
            organizationId: $organizationId,
            organizationUnitId: null,
        );

        $employeeIds = $this->service
            ->visibleEmployeesQuery($this->tenantId)
            ->pluck('id')
            ->all();

        $this->assertSame([$visibleEmployeeId], $employeeIds);
    }

    public function test_visible_employees_query_excludes_employee_from_sibling_unit(): void
    {
        $organizationId = $this->createOrganization();
        $unitAId = $this->createOrganizationUnit($organizationId);
        $unitBId = $this->createOrganizationUnit($organizationId);

        $visibleEmployeeId = $this->createEmployeeWithOpenPlacement(
            organizationId: $organizationId,
            organizationUnitId: $unitAId,
        )['employeeId'];

        $this->createEmployeeWithOpenPlacement(
            organizationId: $organizationId,
            organizationUnitId: $unitBId,
        );

        $this->setOrganizationalContext(
            organizationId: $organizationId,
            organizationUnitId: $unitAId,
        );

        $employeeIds = $this->service
            ->visibleEmployeesQuery($this->tenantId)
            ->pluck('id')
            ->all();

        $this->assertSame([$visibleEmployeeId], $employeeIds);
    }

    public function test_visible_employees_query_excludes_employee_without_open_placement(): void
    {
        $organizationId = $this->createOrganization();
        $this->createEmployeeWithoutPlacement();

        $this->setOrganizationalContext(
            organizationId: $organizationId,
            organizationUnitId: null,
        );

        $this->assertCount(
            0,
            $this->service->visibleEmployeesQuery($this->tenantId)->get(),
        );
    }

    public function test_visible_employees_query_is_empty_when_no_context_is_set(): void
    {
        $organizationId = $this->createOrganization();
        $this->createEmployeeWithOpenPlacement(
            organizationId: $organizationId,
        );

        // Sengaja TIDAK memanggil setOrganizationalContext().
        $this->assertCount(
            0,
            $this->service->visibleEmployeesQuery($this->tenantId)->get(),
        );
    }

    /**
     * INV-HR-011 — daftar tidak pernah bocor lintas tenant, sekalipun
     * organization_id-nya kebetulan sama (mis. UUID collision-proof
     * check: dua tenant beda tidak pernah dianggap satu workspace).
     */
    public function test_visible_employees_query_never_leaks_across_tenants(): void
    {
        $otherTenantId = $this->createTenant();
        $organizationId = $this->createOrganization();
        $this->createEmployeeWithOpenPlacement(
            organizationId: $organizationId,
        );

        $this->setOrganizationalContext(
            organizationId: $organizationId,
            organizationUnitId: null,
        );

        // Query dengan tenantId LAIN dari context yang aktif -> harus
        // kosong (fail-closed), sekalipun context organisasinya "cocok".
        $this->assertCount(
            0,
            $this->service->visibleEmployeesQuery($otherTenantId)->get(),
        );
    }

    private function setOrganizationalContext(
        string $organizationId,
        ?string $organizationUnitId,
    ): void {
        app(OrganizationalContextInterface::class)->setCurrentContext(
            new OrganizationalContext(
                tenantId: $this->tenantId,
                membershipId: UuidV7::generate(),
                assignmentId: UuidV7::generate(),
                organizationId: $organizationId,
                organizationUnitId: $organizationUnitId,
            ),
        );
    }

    private function createTenant(): string
    {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Workforce Scope Tenant',
            'subdomain' => sprintf(
                'workforce-scope-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createOrganization(): string
    {
        $organizationId = UuidV7::generate();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'Workforce Scope Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $organizationId;
    }

    private function createOrganizationUnit(string $organizationId): string
    {
        $unitId = UuidV7::generate();

        DB::table('organization_units')->insert([
            'id' => $unitId,
            'tenant_id' => $this->tenantId,
            'organization_id' => $organizationId,
            'name' => 'Workforce Scope Fixture Unit',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $unitId;
    }

    private function createEmployeeWithoutPlacement(): string
    {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Workforce Scope Fixture Person',
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

        return $employeeId;
    }

    /**
     * @return array{employeeId: string, assignmentId: string, placementId: string}
     */
    private function createEmployeeWithOpenPlacement(
        string $organizationId,
        ?string $organizationUnitId = null,
    ): array {
        $employeeId = $this->createEmployeeWithoutPlacement();

        $membershipId = DB::table('employees')
            ->where('id', $employeeId)
            ->value('membership_id');

        $employmentId = UuidV7::generate();

        DB::table('employments')->insert([
            'id' => $employmentId,
            'tenant_id' => $this->tenantId,
            'employee_id' => $employeeId,
            'status' => 'ACTIVE',
            'start_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignmentId = UuidV7::generate();

        DB::table('organizational_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $membershipId,
            'organization_id' => $organizationId,
            'organization_unit_id' => $organizationUnitId,
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

        return [
            'employeeId' => $employeeId,
            'assignmentId' => $assignmentId,
            'placementId' => $placementId,
        ];
    }
}
