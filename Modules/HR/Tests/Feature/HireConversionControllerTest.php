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
use Modules\HR\Contracts\RecruitmentCandidateIdentifierRepositoryInterface;
use Modules\HR\Database\Seeders\HrAuthorizationCatalogSeeder;
use Modules\HR\Models\RecruitmentCandidate;
use Modules\HR\Services\RecruitmentApplicationLifecycleService;
use Modules\HR\Services\RecruitmentVacancyLifecycleService;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class HireConversionControllerTest extends TestCase
{
    use RefreshDatabase;
    use GrantsAuthorizationRole;

    private string $tenantId;
    private string $operatorUserId;
    private string $operatorMembershipId;
    private string $employmentTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HrAuthorizationCatalogSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();

        $this->createTenantFixture();
        $this->createOperatorFixture();
        $this->employmentTypeId = $this->createEmploymentType();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_store_succeeds_and_provisions_employee(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);
        $applicationId = $this->createHiringApprovedApplicationFixture('3221111111111111');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.applications.hire-conversion', ['applicationId' => $applicationId], false),
                [
                    'employment_type_id' => $this->employmentTypeId,
                    'start_date' => '2026-09-01',
                    'confirm_create_new_person' => true,
                ],
            );

        $response
            ->assertOk()
            ->assertJsonPath('data.conversion_status', 'SUCCEEDED');

        $this->assertDatabaseHas('recruitment_applications', [
            'id' => $applicationId,
            'status' => 'HIRED',
        ]);
    }

    public function test_store_is_idempotent_on_repeated_request(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);
        $applicationId = $this->createHiringApprovedApplicationFixture('3222222222222222');

        $payload = [
            'employment_type_id' => $this->employmentTypeId,
            'start_date' => '2026-09-01',
            'confirm_create_new_person' => true,
        ];

        $first = $this->withToken($this->issueToken())->postJson(
            route('api.v1.hr.recruitment.applications.hire-conversion', ['applicationId' => $applicationId], false),
            $payload,
        );

        $second = $this->withToken($this->issueToken())->postJson(
            route('api.v1.hr.recruitment.applications.hire-conversion', ['applicationId' => $applicationId], false),
            $payload,
        );

        $second->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.employment_id'), $second->json('data.employment_id'));
    }

    public function test_store_returns_conflict_when_not_hiring_approved(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);
        $vacancyId = $this->createOpenVacancy();
        $candidateId = $this->createCandidateWithIdentifierFixture('3223333333333333');

        $this->activateTenantContext();
        $applicationService = new RecruitmentApplicationLifecycleService();
        $applicationId = $applicationService->submitApplication($this->tenantId, $vacancyId, $candidateId)->id;
        app(TenantContextInterface::class)->clear();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.applications.hire-conversion', ['applicationId' => $applicationId], false),
                [
                    'employment_type_id' => $this->employmentTypeId,
                    'start_date' => '2026-09-01',
                ],
            );

        $response
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertJsonPath('code', 'HIRE_CONVERSION_CONFLICT');
    }

    public function test_store_is_forbidden_with_manage_only_permission(): void
    {
        $this->grantSinglePermissionRole(['hr.recruitment.manage']);
        $applicationId = $this->createHiringApprovedApplicationFixture('3224444444444444');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.applications.hire-conversion', ['applicationId' => $applicationId], false),
                [
                    'employment_type_id' => $this->employmentTypeId,
                    'start_date' => '2026-09-01',
                    'confirm_create_new_person' => true,
                ],
            );

        $response->assertForbidden();
    }

    public function test_store_validation_rejects_missing_employment_type_id(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);
        $applicationId = $this->createHiringApprovedApplicationFixture('3225555555555555');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.applications.hire-conversion', ['applicationId' => $applicationId], false),
                ['start_date' => '2026-09-01'],
            );

        $response->assertUnprocessable();
    }

    private function activateTenantContext(): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        app(TenantContextInterface::class)->setCurrentTenant($tenant);
    }

    private function createHiringApprovedApplicationFixture(string $nationalId): string
    {
        $vacancyId = $this->createOpenVacancy();
        $candidateId = $this->createCandidateWithIdentifierFixture($nationalId);

        $this->activateTenantContext();
        $applicationService = new RecruitmentApplicationLifecycleService();
        $application = $applicationService->submitApplication($this->tenantId, $vacancyId, $candidateId);
        $applicationService->startProcessing($this->tenantId, $application->id);
        $applicationService->approveForHiring($this->tenantId, $application->id, $this->operatorMembershipId);
        app(TenantContextInterface::class)->clear();

        return $application->id;
    }

    private function createCandidateWithIdentifierFixture(string $nationalId): string
    {
        $this->activateTenantContext();

        $candidateId = RecruitmentCandidate::create([
            'display_name' => 'Kandidat Uji Hire Conversion HTTP ' . Str::random(6),
        ])->id;

        app(RecruitmentCandidateIdentifierRepositoryInterface::class)->store(
            tenantId: $this->tenantId,
            candidateId: $candidateId,
            type: 'NATIONAL_ID',
            issuingCountryCode: 'ID',
            rawValue: $nationalId,
        );

        app(TenantContextInterface::class)->clear();

        return $candidateId;
    }

    private function createOpenVacancy(): string
    {
        $positionId = UuidV7::generate();
        DB::table('positions')->insert([
            'id' => $positionId,
            'tenant_id' => $this->tenantId,
            'code' => 'POS-' . Str::upper(Str::random(6)),
            'name' => 'Posisi Uji Hire Conversion HTTP',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $organizationId = UuidV7::generate();
        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'Hire Conversion HTTP Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->activateTenantContext();

        $vacancyService = new RecruitmentVacancyLifecycleService();
        $vacancy = $vacancyService->createDraft($this->tenantId, [
            'code' => 'VAC-HIRE-HTTP-' . Str::upper(Str::random(6)),
            'title' => 'Guru Matematika',
            'position_id' => $positionId,
            'organization_id' => $organizationId,
            'requested_headcount' => 1,
            'created_by_membership_id' => $this->operatorMembershipId,
        ]);

        $vacancyService->submit($this->tenantId, $vacancy->id);
        $vacancyService->approve($this->tenantId, $vacancy->id, $this->operatorMembershipId);
        $openVacancyId = $vacancyService->open($this->tenantId, $vacancy->id)->id;

        app(TenantContextInterface::class)->clear();

        return $openVacancyId;
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
            'name' => 'Hire Conversion HTTP Tenant',
            'subdomain' => sprintf(
                'hire-conversion-http-%s',
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
            'name' => 'Hire Conversion HTTP Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => sprintf(
                'hire-conversion-http-operator-%s@educore.test',
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

    private function createEmploymentType(): string
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
