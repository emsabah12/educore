<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\HR\Database\Seeders\HrAuthorizationCatalogSeeder;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class RecruitmentVacancyControllerTest extends TestCase
{
    use RefreshDatabase;
    use GrantsAuthorizationRole;

    private string $tenantId;
    private string $operatorUserId;
    private string $operatorMembershipId;
    private string $positionId;
    private string $organizationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HrAuthorizationCatalogSeeder::class);

        $this->tenantId = UuidV7::generate();
        $this->operatorUserId = UuidV7::generate();
        $this->operatorMembershipId = UuidV7::generate();

        $this->createTenantFixture();
        $this->createOperatorFixture();

        $this->positionId = UuidV7::generate();
        DB::table('positions')->insert([
            'id' => $this->positionId,
            'tenant_id' => $this->tenantId,
            'code' => 'POS-' . Str::upper(Str::random(6)),
            'name' => 'Posisi Uji Vacancy HTTP',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->organizationId = UuidV7::generate();
        DB::table('organizations')->insert([
            'id' => $this->organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'Vacancy HTTP Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        app(\Modules\Core\Tenancy\Contracts\TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_store_creates_draft_vacancy(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.vacancies.store', [], false),
                $this->vacancyPayload('VAC-HTTP-001'),
            );

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT');
    }

    public function test_store_is_forbidden_without_hr_recruitment_manage_permission(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.vacancies.store', [], false),
                $this->vacancyPayload('VAC-HTTP-002'),
            );

        $response->assertForbidden();
    }

    public function test_submit_transitions_draft_to_pending_approval(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);
        $vacancyId = $this->createDraftVacancyViaApi('VAC-HTTP-003');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.vacancies.submit', ['vacancyId' => $vacancyId], false),
            );

        $response->assertOk()->assertJsonPath('data.status', 'PENDING_APPROVAL');
    }

    /**
     * hr-officer role di seeder ini memang mendapat SEMUA permission
     * (termasuk approve) — test ini membuktikan endpoint approve
     * benar-benar dijaga permission `hr.recruitment.approve` yang
     * terpisah dari `hr.recruitment.manage`, dengan operator yang HANYA
     * diberi permission manage (bukan lewat role hr-officer bawaan).
     */
    public function test_approve_is_forbidden_with_manage_only_permission(): void
    {
        $this->grantSinglePermissionRole('manage-only', ['hr.recruitment.manage']);
        $vacancyId = $this->createDraftVacancyViaApi('VAC-HTTP-004');

        $this->withToken($this->issueToken())->postJson(
            route('api.v1.hr.recruitment.vacancies.submit', ['vacancyId' => $vacancyId], false),
        );

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.vacancies.approve', ['vacancyId' => $vacancyId], false),
                ['reason' => 'Disetujui.'],
            );

        $response->assertForbidden();
    }

    public function test_approve_transitions_pending_approval_to_approved(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);
        $vacancyId = $this->createDraftVacancyViaApi('VAC-HTTP-005');

        $this->withToken($this->issueToken())->postJson(
            route('api.v1.hr.recruitment.vacancies.submit', ['vacancyId' => $vacancyId], false),
        );

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.vacancies.approve', ['vacancyId' => $vacancyId], false),
                ['reason' => 'Disetujui, kebutuhan mendesak.'],
            );

        $response->assertOk()->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_open_returns_conflict_for_non_approved_vacancy(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);
        $vacancyId = $this->createDraftVacancyViaApi('VAC-HTTP-006');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.vacancies.open', ['vacancyId' => $vacancyId], false),
            );

        $response
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertJsonPath('code', 'RECRUITMENT_VACANCY_CONFLICT');
    }

    public function test_submit_returns_not_found_for_unknown_vacancy(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.vacancies.submit', ['vacancyId' => UuidV7::generate()], false),
            );

        $response
            ->assertNotFound()
            ->assertJsonPath('code', 'RECRUITMENT_VACANCY_NOT_FOUND');
    }

    public function test_index_lists_vacancies(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);
        $this->createDraftVacancyViaApi('VAC-HTTP-007');
        $this->createDraftVacancyViaApi('VAC-HTTP-008');

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(route('api.v1.hr.recruitment.vacancies.index', [], false));

        $response->assertOk()->assertJsonPath('meta.total', 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function vacancyPayload(string $code): array
    {
        return [
            'code' => $code,
            'title' => 'Guru Matematika',
            'position_id' => $this->positionId,
            'organization_id' => $this->organizationId,
            'requested_headcount' => 1,
        ];
    }

    private function createDraftVacancyViaApi(string $code): string
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.recruitment.vacancies.store', [], false),
                $this->vacancyPayload($code),
            );

        return $response->json('data.id');
    }

    /**
     * @param list<string> $permissionNames
     */
    private function grantSinglePermissionRole(string $roleName, array $permissionNames): void
    {
        $roleId = UuidV7::generate();

        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => $roleName . '-' . Str::lower(Str::random(6)),
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

    private function createTenantFixture(): void
    {
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Vacancy HTTP Tenant',
            'subdomain' => sprintf(
                'vacancy-http-%s',
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
            'name' => 'Vacancy HTTP Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => sprintf(
                'vacancy-operator-%s@educore.test',
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
