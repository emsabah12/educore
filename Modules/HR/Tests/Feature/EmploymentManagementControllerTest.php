<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Database\Seeders\HrAuthorizationCatalogSeeder;
use Modules\HR\Models\EmploymentType;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class EmploymentManagementControllerTest extends TestCase
{
    use RefreshDatabase;
    use GrantsAuthorizationRole;

    private string $tenantId;
    private string $operatorUserId;
    private string $operatorMembershipId;
    private string $employeeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HrAuthorizationCatalogSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();

        $this->createAuthenticatedTenantFixture();
        $this->employeeId = $this->createEmployeeFixture();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_store_creates_planned_employment_for_employee(): void
    {
        $this->grantRole(
            $this->operatorMembershipId,
            HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE,
        );

        $employmentTypeId = $this->createEmploymentType();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employees.employments.store',
                    ['employeeId' => $this->employeeId],
                    false,
                ),
                [
                    'employment_type_id' => $employmentTypeId,
                    'start_date' => '2026-09-01',
                ],
            );

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'PLANNED')
            ->assertJsonPath('data.employee_id', $this->employeeId);
    }

    public function test_store_is_forbidden_without_hr_employments_manage_permission(): void
    {
        // Sengaja TIDAK memanggil grantRole() supaya operator tidak
        // punya permission apa pun.
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employees.employments.store',
                    ['employeeId' => $this->employeeId],
                    false,
                ),
                ['start_date' => '2026-09-01'],
            );

        $response
            ->assertForbidden()
            ->assertJsonPath('code', 'AUTHORIZATION_DENIED');
    }

    public function test_store_rejects_unknown_employment_type_id(): void
    {
        $this->grantRole(
            $this->operatorMembershipId,
            HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employees.employments.store',
                    ['employeeId' => $this->employeeId],
                    false,
                ),
                [
                    'employment_type_id' => UuidV7::generate(),
                    'start_date' => '2026-09-01',
                ],
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employment_type_id']);
    }

    public function test_activate_transitions_planned_employment_to_active(): void
    {
        $this->grantRole(
            $this->operatorMembershipId,
            HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE,
        );

        $employmentTypeId = $this->createEmploymentType();                      // ← BARU
        $employmentId = $this->createPlannedEmploymentViaApi($employmentTypeId); // ← diberi argumen

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.activate',
                    ['employmentId' => $employmentId],
                    false,
                ),
            );

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'ACTIVE');
    }

    public function test_activate_returns_conflict_when_employment_already_active(): void
    {
        $this->grantRole(
            $this->operatorMembershipId,
            HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE,
        );

        $employmentTypeId = $this->createEmploymentType();                      // ← BARU
        $employmentId = $this->createPlannedEmploymentViaApi($employmentTypeId); // ← diberi argumen


        $this->withToken($this->issueToken())->postJson(
            route(
                'api.v1.hr.employments.activate',
                ['employmentId' => $employmentId],
                false,
            ),
        );

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.activate',
                    ['employmentId' => $employmentId],
                    false,
                ),
            );

        $response
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertJsonPath('code', 'EMPLOYMENT_LIFECYCLE_CONFLICT');
    }

    public function test_activate_returns_not_found_for_unknown_employment_id(): void
    {
        $this->grantRole(
            $this->operatorMembershipId,
            HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE,
        );

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.activate',
                    ['employmentId' => UuidV7::generate()],
                    false,
                ),
            );

        $response
            ->assertNotFound()
            ->assertJsonPath('code', 'EMPLOYMENT_NOT_FOUND');
    }

    public function test_cancel_transitions_planned_employment_to_cancelled(): void
    {
        $this->grantRole(
            $this->operatorMembershipId,
            HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE,
        );

        $employmentId = $this->createPlannedEmploymentViaApi();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.cancel',
                    ['employmentId' => $employmentId],
                    false,
                ),
            );

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_index_lists_employments_for_employee(): void
    {
        $this->grantRole(
            $this->operatorMembershipId,
            HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE,
        );

        $this->createPlannedEmploymentViaApi();
        $this->createPlannedEmploymentViaApi();

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.hr.employees.employments.index',
                    ['employeeId' => $this->employeeId],
                    false,
                ),
            );

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    private function createPlannedEmploymentViaApi(?string $employmentTypeId = null,): string
    {
        $payload = ['start_date' => '2026-09-01'];

        if ($employmentTypeId !== null) {
            $payload['employment_type_id'] = $employmentTypeId;
        }

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employees.employments.store',
                    ['employeeId' => $this->employeeId],
                    false,
                ),
                $payload,
            );

        return $response->json('data.id');
    }

    private function createEmploymentType(): string
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        app(TenantContextInterface::class)->setCurrentTenant($tenant);

        return EmploymentType::create([
            'code' => 'TETAP',
            'name' => 'Pegawai Tetap',
            'is_active' => true,
        ])->id;
    }

    private function createAuthenticatedTenantFixture(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Employment API Tenant',
            'subdomain' => sprintf(
                'employment-api-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $operatorPersonId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $operatorPersonId,
            'name' => 'Employment API Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $operatorPersonId,
            'email' => sprintf(
                'employment-operator-%s@educore.test',
                Str::lower(Str::random(10)),
            ),
            'password' => 'not-used-by-token-test',
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $this->operatorMembershipId,
            'person_id' => $operatorPersonId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createEmployeeFixture(): string
    {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Employment API Employee',
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
