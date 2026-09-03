<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\HR\Database\Seeders\HrAuthorizationCatalogSeeder;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class EmploymentPlacementAndPositionAssignmentControllerTest extends TestCase
{
    use RefreshDatabase;
    use GrantsAuthorizationRole;

    private string $tenantId;
    private string $operatorUserId;
    private string $operatorMembershipId;
    private string $employeeId;
    private string $employeeMembershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HrAuthorizationCatalogSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();

        $this->createAuthenticatedTenantFixture();
        [$this->employeeId, $this->employeeMembershipId] = $this->createEmployeeFixture();

        $this->grantRole(
            $this->operatorMembershipId,
            HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE,
        );
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_placement_store_creates_open_placement(): void
    {
        $employmentId = $this->createActiveEmploymentViaApi();
        $assignmentId = $this->createOrganizationalAssignmentFixture();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.placements.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                [
                    'organizational_assignment_id' => $assignmentId,
                    'effective_from' => '2026-01-01',
                    'is_primary' => true,
                ],
            );

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.is_primary', true);
    }

    public function test_placement_index_lists_placements_for_employment(): void
    {
        $employmentId = $this->createActiveEmploymentViaApi();
        $assignmentId = $this->createOrganizationalAssignmentFixture();

        $this->withToken($this->issueToken())->postJson(
            route(
                'api.v1.hr.employments.placements.store',
                ['employmentId' => $employmentId],
                false,
            ),
            [
                'organizational_assignment_id' => $assignmentId,
                'effective_from' => '2026-01-01',
            ],
        );

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.hr.employments.placements.index',
                    ['employmentId' => $employmentId],
                    false,
                ),
            );

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_position_assignment_store_creates_tenant_level_assignment(): void
    {
        $employmentId = $this->createActiveEmploymentViaApi();
        $positionId = $this->createPositionFixture();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.position-assignments.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                [
                    'position_id' => $positionId,
                    'effective_from' => '2026-01-01',
                ],
            );

        $response
            ->assertCreated()
            ->assertJsonPath('data.employment_placement_id', null);
    }

    public function test_position_assignment_index_lists_assignments_for_employment(): void
    {
        $employmentId = $this->createActiveEmploymentViaApi();
        $positionId = $this->createPositionFixture();

        $this->withToken($this->issueToken())->postJson(
            route(
                'api.v1.hr.employments.position-assignments.store',
                ['employmentId' => $employmentId],
                false,
            ),
            [
                'position_id' => $positionId,
                'effective_from' => '2026-01-01',
            ],
        );

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.hr.employments.position-assignments.index',
                    ['employmentId' => $employmentId],
                    false,
                ),
            );

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_end_transitions_active_employment_to_ended(): void
    {
        $employmentId = $this->createActiveEmploymentViaApi();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.end',
                    ['employmentId' => $employmentId],
                    false,
                ),
                ['end_date' => '2026-06-01'],
            );

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'ENDED');

        $this->assertStringStartsWith(
            '2026-06-01',
            $response->json('data.end_date'),
        );
    }

    public function test_end_is_forbidden_without_hr_employments_end_permission(): void
    {
        // Operator baru sama sekali TANPA role apa pun (berbeda dari
        // $this->operatorMembershipId yang sudah diberi hr-officer di
        // setUp()), supaya benar-benar menguji penegakan permission
        // hr.employments.end, bukan sekadar "belum login".
        $unauthorizedUserId = UuidV7::generate();
        $unauthorizedMembershipId = UuidV7::generate();
        $this->createOperatorFixture($unauthorizedUserId, $unauthorizedMembershipId);

        $employmentId = $this->createActiveEmploymentViaApi();

        $token = app(TokenManagerInterface::class)->issueToken(
            $unauthorizedUserId,
            $this->tenantId,
            ['membership_id' => $unauthorizedMembershipId],
        );

        $response = $this
            ->withToken($token)
            ->postJson(
                route(
                    'api.v1.hr.employments.end',
                    ['employmentId' => $employmentId],
                    false,
                ),
                ['end_date' => '2026-06-01'],
            );

        $response->assertForbidden();
    }

    public function test_end_returns_conflict_for_non_active_employment(): void
    {
        $plannedEmploymentId = $this->createPlannedEmploymentViaApi();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.end',
                    ['employmentId' => $plannedEmploymentId],
                    false,
                ),
                ['end_date' => '2026-06-01'],
            );

        $response
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertJsonPath('code', 'EMPLOYMENT_LIFECYCLE_CONFLICT');
    }

    private function createActiveEmploymentViaApi(): string
    {
        $employmentTypeId = $this->createEmploymentTypeFixture();

        $storeResponse = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employees.employments.store',
                    ['employeeId' => $this->employeeId],
                    false,
                ),
                [
                    'employment_type_id' => $employmentTypeId,
                    'start_date' => '2026-01-01',
                ],
            );

        $employmentId = $storeResponse->json('data.id');

        $this->withToken($this->issueToken())->postJson(
            route(
                'api.v1.hr.employments.activate',
                ['employmentId' => $employmentId],
                false,
            ),
        );

        return $employmentId;
    }

    private function createPlannedEmploymentViaApi(): string
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employees.employments.store',
                    ['employeeId' => $this->employeeId],
                    false,
                ),
                ['start_date' => '2026-01-01'],
            );

        return $response->json('data.id');
    }

    private function createEmploymentTypeFixture(): string
    {
        $employmentTypeId = UuidV7::generate();

        DB::table('employment_types')->insert([
            'id' => $employmentTypeId,
            'tenant_id' => $this->tenantId,
            'code' => 'TETAP-' . Str::upper(Str::random(6)),
            'name' => 'Pegawai Tetap',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employmentTypeId;
    }

    private function createOrganizationalAssignmentFixture(): string
    {
        $organizationId = UuidV7::generate();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'Placement HTTP Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignmentId = UuidV7::generate();

        DB::table('organizational_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $this->employeeMembershipId,
            'organization_id' => $organizationId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $assignmentId;
    }

    private function createPositionFixture(): string
    {
        $positionId = UuidV7::generate();

        DB::table('positions')->insert([
            'id' => $positionId,
            'tenant_id' => $this->tenantId,
            'code' => 'POS-' . Str::upper(Str::random(6)),
            'name' => 'Posisi Uji HTTP',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $positionId;
    }

    private function createAuthenticatedTenantFixture(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Placement HTTP Tenant',
            'subdomain' => sprintf(
                'placement-http-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createOperatorFixture(
            $this->operatorUserId,
            $this->operatorMembershipId,
        );
    }

    private function createOperatorFixture(
        string $userId,
        string $membershipId,
    ): void {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Placement HTTP Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $userId,
            'person_id' => $personId,
            'email' => sprintf(
                'placement-operator-%s@educore.test',
                Str::lower(Str::random(10)),
            ),
            'password' => 'not-used-by-token-test',
            'status' => 'ACTIVE',
            'is_superadmin' => false,
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
    }

    /**
     * @return array{0: string, 1: string} [employeeId, membershipId]
     */
    private function createEmployeeFixture(): array
    {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Placement HTTP Employee',
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

    private function issueToken(): string
    {
        return app(TokenManagerInterface::class)
            ->issueToken(
                $this->operatorUserId,
                $this->tenantId,
                ['membership_id' => $this->operatorMembershipId],
            );
    }
}
