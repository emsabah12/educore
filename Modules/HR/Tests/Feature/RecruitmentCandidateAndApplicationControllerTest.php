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
use Modules\HR\Models\RecruitmentVacancy;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class RecruitmentCandidateAndApplicationControllerTest extends TestCase
{
    use RefreshDatabase;
    use GrantsAuthorizationRole;

    private string $tenantId;
    private string $operatorUserId;
    private string $operatorMembershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HrAuthorizationCatalogSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();

        $this->createTenantFixture();
        $this->createOperatorFixture();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_store_candidate_creates_candidate(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.candidates.store', [], false),
                [
                    'display_name' => 'Budi Kandidat',
                    'primary_email' => 'budi@example.test',
                ],
            );

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'ACTIVE');
    }

    public function test_store_candidate_with_identifier_succeeds(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.candidates.store', [], false),
                [
                    'display_name' => 'Siti Kandidat',
                    'identifiers' => [
                        [
                            'type' => 'NATIONAL_ID',
                            'issuing_country_code' => 'ID',
                            'value' => '3201234567890099',
                        ],
                    ],
                ],
            );

        $response->assertCreated();
    }

    public function test_store_candidate_identifier_conflict_returns_409(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $identifierPayload = [
            'type' => 'NATIONAL_ID',
            'issuing_country_code' => 'ID',
            'value' => '3209999999999999',
        ];

        $this->withToken($this->issueToken())->postJson(
            route('api.v1.hr.recruitment.candidates.store', [], false),
            ['display_name' => 'Kandidat Pertama', 'identifiers' => [$identifierPayload]],
        );

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.candidates.store', [], false),
                ['display_name' => 'Kandidat Kedua', 'identifiers' => [$identifierPayload]],
            );

        $response
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertJsonPath('code', 'RECRUITMENT_CANDIDATE_IDENTIFIER_CONFLICT');
    }

    public function test_store_candidate_is_forbidden_without_permission(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.candidates.store', [], false),
                ['display_name' => 'Kandidat Tanpa Izin'],
            );

        $response->assertForbidden();
    }

    public function test_submit_application_creates_submitted_application(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);
        $vacancyId = $this->createOpenVacancy();
        $candidateId = $this->createCandidateViaApi();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.vacancies.applications.store', ['vacancyId' => $vacancyId], false),
                ['candidate_id' => $candidateId],
            );

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'SUBMITTED');
    }

    public function test_approve_for_hiring_is_forbidden_with_manage_only_permission(): void
    {
        $this->grantSinglePermissionRole(['hr.recruitment.manage']);
        $vacancyId = $this->createOpenVacancy();
        $candidateId = $this->createCandidateViaApi();

        $applicationResponse = $this->withToken($this->issueToken())->postJson(
            route('api.v1.hr.recruitment.vacancies.applications.store', ['vacancyId' => $vacancyId], false),
            ['candidate_id' => $candidateId],
        );
        $applicationId = $applicationResponse->json('data.id');

        $this->withToken($this->issueToken())->postJson(
            route('api.v1.hr.recruitment.applications.start-processing', ['applicationId' => $applicationId], false),
        );

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.applications.approve-for-hiring', ['applicationId' => $applicationId], false),
            );

        $response->assertForbidden();
    }

    public function test_index_lists_applications_for_vacancy(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);
        $vacancyId = $this->createOpenVacancy();
        $candidateId = $this->createCandidateViaApi();

        $this->withToken($this->issueToken())->postJson(
            route('api.v1.hr.recruitment.vacancies.applications.store', ['vacancyId' => $vacancyId], false),
            ['candidate_id' => $candidateId],
        );

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route('api.v1.hr.recruitment.vacancies.applications.index', ['vacancyId' => $vacancyId], false),
            );

        $response->assertOk()->assertJsonPath('meta.total', 1);
    }

    private function createCandidateViaApi(): string
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.candidates.store', [], false),
                ['display_name' => 'Kandidat Fixture ' . Str::random(6)],
            );

        return $response->json('data.id');
    }

    private function createOpenVacancy(): string
    {
        $positionId = UuidV7::generate();
        DB::table('positions')->insert([
            'id' => $positionId,
            'tenant_id' => $this->tenantId,
            'code' => 'POS-' . Str::upper(Str::random(6)),
            'name' => 'Posisi Uji Application HTTP',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $organizationId = UuidV7::generate();
        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'Application HTTP Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vacancyId = UuidV7::generate();
        DB::table('recruitment_vacancies')->insert([
            'id' => $vacancyId,
            'tenant_id' => $this->tenantId,
            'code' => 'VAC-APP-HTTP-' . Str::upper(Str::random(6)),
            'title' => 'Guru Matematika',
            'position_id' => $positionId,
            'organization_id' => $organizationId,
            'requested_headcount' => 1,
            'status' => RecruitmentVacancy::STATUS_OPEN,
            'created_by_membership_id' => $this->operatorMembershipId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $vacancyId;
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

    private function createTenantFixture(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Application HTTP Tenant',
            'subdomain' => sprintf(
                'application-http-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOperatorFixture(): void
    {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Application HTTP Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => sprintf(
                'application-http-operator-%s@educore.test',
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
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param list<string> $permissionNames
     */
    private function grantSinglePermissionRole(array $permissionNames): void
    {
        $roleId = UuidV7::generate();

        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'manage-only-' . Str::lower(Str::random(6)),
            'display_name' => 'Manage Only Test Role',
            'description' => 'Test-only role for permission-separation assertions.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($permissionNames as $permissionName) {
            $permissionId = DB::table('permissions')
                ->where('name', $permissionName)
                ->value('id');

            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }

        DB::table('membership_roles')->insertOrIgnore([
            'membership_id' => $this->operatorMembershipId,
            'role_id' => $roleId,
        ]);
    }
}
