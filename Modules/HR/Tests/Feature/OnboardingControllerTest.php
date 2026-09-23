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
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

final class OnboardingControllerTest extends TestCase
{
    use GrantsAuthorizationRole;
    use RefreshDatabase;

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

    public function test_store_template_creates_template_with_tasks(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.onboarding.templates.store', [], false),
                [
                    'code' => 'STANDARD',
                    'name' => 'Onboarding Guru Standar',
                    'tasks' => [
                        ['code' => 'SUBMIT_ID_CARD', 'title' => 'Kumpulkan KTP', 'category' => 'DOCUMENT', 'sequence' => 1],
                        ['code' => 'ORIENTATION', 'title' => 'Orientasi', 'category' => 'ORIENTATION', 'sequence' => 2],
                    ],
                ],
            );

        $response->assertCreated();
        $this->assertCount(2, $response->json('data.tasks'));
    }

    public function test_store_case_creates_case_for_application(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);
        $applicationId = $this->createApplicationFixture();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.onboarding.cases.store', ['applicationId' => $applicationId], false),
            );

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'NOT_STARTED');
    }

    public function test_full_task_lifecycle_advances_case_to_ready_for_activation(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);
        $applicationId = $this->createApplicationFixture();

        $caseResponse = $this->withToken($this->issueToken())->postJson(
            route('api.v1.hr.onboarding.cases.store', ['applicationId' => $applicationId], false),
        );
        $caseId = $caseResponse->json('data.id');

        $startResponse = $this->withToken($this->issueToken())->postJson(
            route('api.v1.hr.onboarding.cases.start', ['caseId' => $caseId], false),
        );

        // §Perbaikan bug — OnboardingCaseResource (OpenAPI) menjanjikan
        // `tasks` sebagai field WAJIB di SEMUA response case (persis
        // seperti store()). Tanpa ini, frontend (OnboardingCaseManager)
        // yang mengganti state dengan response start() akan crash di
        // `onboardingCase.tasks.length` -- persis bug yang dilaporkan.
        $startResponse
            ->assertOk()
            ->assertJsonPath('data.status', 'IN_PROGRESS');

        $this->assertIsArray(
            $startResponse->json('data.tasks'),
            'start() response must include the tasks relation, matching the OpenAPI contract and store()\'s behaviour -- otherwise the frontend crashes replacing state with this response.',
        );

        $taskId = $this->createTaskDirectly($caseId);

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.onboarding.tasks.complete', ['taskId' => $taskId], false),
                ['note' => 'Sudah dikumpulkan.'],
            );

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertDatabaseHas('onboarding_cases', [
            'id' => $caseId,
            'status' => 'READY_FOR_ACTIVATION',
        ]);
    }

    public function test_start_response_includes_tasks_snapshotted_from_template(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);

        $templateResponse = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.onboarding.templates.store', [], false),
                [
                    'code' => 'START-RESPONSE-TASKS-CHECK',
                    'name' => 'Onboarding Guru — Cek Tasks di Respons Start',
                    'tasks' => [
                        ['code' => 'SUBMIT_ID_CARD', 'title' => 'Kumpulkan KTP', 'category' => 'DOCUMENT', 'sequence' => 1],
                        ['code' => 'ORIENTATION', 'title' => 'Orientasi', 'category' => 'ORIENTATION', 'sequence' => 2],
                    ],
                ],
            );

        $templateId = $templateResponse->json('data.id');
        $applicationId = $this->createApplicationFixture();

        $caseResponse = $this->withToken($this->issueToken())->postJson(
            route('api.v1.hr.onboarding.cases.store', ['applicationId' => $applicationId], false),
            ['template_id' => $templateId],
        );

        $caseId = $caseResponse->json('data.id');

        // §Bukti paling kuat dan paling realistis untuk gap ini --
        // Case-nya SUDAH punya 2 task ter-snapshot dari Template SEBELUM
        // start() dipanggil. Kalau bug ini kambuh (start() tidak
        // load('tasks')), assertion di bawah akan gagal karena
        // data.tasks jadi array kosong -- BUKAN cuma "key tidak ada",
        // tapi kehilangan data task yang SUDAH nyata dibuat.
        $startResponse = $this->withToken($this->issueToken())->postJson(
            route('api.v1.hr.onboarding.cases.start', ['caseId' => $caseId], false),
        );

        $startResponse->assertOk();

        $this->assertCount(
            2,
            $startResponse->json('data.tasks'),
            'start() response must include the two Tasks already snapshotted from the Template -- this is exactly what crashed the frontend Onboarding Case screen (OnboardingCaseManager reads onboardingCase.tasks.length immediately after this response replaces its state).',
        );

        $this->assertSame(
            'Kumpulkan KTP',
            $startResponse->json('data.tasks.0.title'),
        );
    }

    public function test_waive_task_is_forbidden_with_manage_only_permission(): void
    {
        $this->grantSinglePermissionRole(['hr.onboarding.manage']);
        $applicationId = $this->createApplicationFixture();

        $caseResponse = $this->withToken($this->issueToken())->postJson(
            route('api.v1.hr.onboarding.cases.store', ['applicationId' => $applicationId], false),
        );
        $caseId = $caseResponse->json('data.id');
        $this->withToken($this->issueToken())->postJson(
            route('api.v1.hr.onboarding.cases.start', ['caseId' => $caseId], false),
        );
        $taskId = $this->createTaskDirectly($caseId);

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.onboarding.tasks.waive', ['taskId' => $taskId], false),
                ['note' => 'Dibebaskan.'],
            );

        $response->assertForbidden();
    }

    public function test_cancel_case_succeeds_with_reason(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);
        $applicationId = $this->createApplicationFixture();

        $caseResponse = $this->withToken($this->issueToken())->postJson(
            route('api.v1.hr.onboarding.cases.store', ['applicationId' => $applicationId], false),
        );
        $caseId = $caseResponse->json('data.id');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.onboarding.cases.cancel', ['caseId' => $caseId], false),
                ['reason' => 'Kandidat mengundurkan diri.'],
            );

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        // §Perbaikan bug — sama persis dengan start() di atas.
        $this->assertIsArray(
            $response->json('data.tasks'),
            'cancel() response must include the tasks relation, matching the OpenAPI contract and store()\'s behaviour -- otherwise the frontend crashes replacing state with this response.',
        );
    }

    public function test_cancel_case_validation_rejects_missing_reason(): void
    {
        $this->grantRole($this->operatorMembershipId, HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE);
        $applicationId = $this->createApplicationFixture();

        $caseResponse = $this->withToken($this->issueToken())->postJson(
            route('api.v1.hr.onboarding.cases.store', ['applicationId' => $applicationId], false),
        );
        $caseId = $caseResponse->json('data.id');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.onboarding.cases.cancel', ['caseId' => $caseId], false),
                [],
            );

        $response->assertUnprocessable();
    }

    public function test_store_case_is_forbidden_without_permission(): void
    {
        $applicationId = $this->createApplicationFixture();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.onboarding.cases.store', ['applicationId' => $applicationId], false),
            );

        $response->assertForbidden();
    }

    private function createTaskDirectly(string $caseId): string
    {
        $taskId = UuidV7::generate();

        DB::table('onboarding_tasks')->insert([
            'id' => $taskId,
            'tenant_id' => $this->tenantId,
            'onboarding_case_id' => $caseId,
            'code' => 'SUBMIT_ID_CARD',
            'title' => 'Kumpulkan KTP',
            'category' => 'DOCUMENT',
            'sequence' => 1,
            'is_required' => true,
            'requires_evidence' => false,
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $taskId;
    }

    private function createApplicationFixture(): string
    {
        $positionId = UuidV7::generate();
        DB::table('positions')->insert([
            'id' => $positionId,
            'tenant_id' => $this->tenantId,
            'code' => 'POS-'.Str::upper(Str::random(6)),
            'name' => 'Posisi Uji Onboarding HTTP',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $organizationId = UuidV7::generate();
        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'Onboarding HTTP Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vacancyId = UuidV7::generate();
        DB::table('recruitment_vacancies')->insert([
            'id' => $vacancyId,
            'tenant_id' => $this->tenantId,
            'code' => 'VAC-OB-HTTP-'.Str::upper(Str::random(6)),
            'title' => 'Guru Matematika',
            'position_id' => $positionId,
            'organization_id' => $organizationId,
            'requested_headcount' => 1,
            'status' => RecruitmentVacancy::STATUS_OPEN,
            'created_by_membership_id' => $this->operatorMembershipId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $candidateId = UuidV7::generate();
        DB::table('recruitment_candidates')->insert([
            'id' => $candidateId,
            'tenant_id' => $this->tenantId,
            'display_name' => 'Kandidat Uji Onboarding HTTP '.Str::random(6),
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $applicationId = UuidV7::generate();
        DB::table('recruitment_applications')->insert([
            'id' => $applicationId,
            'tenant_id' => $this->tenantId,
            'vacancy_id' => $vacancyId,
            'candidate_id' => $candidateId,
            'status' => 'SUBMITTED',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $applicationId;
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
            'name' => 'Onboarding HTTP Tenant',
            'subdomain' => sprintf(
                'onboarding-http-%s',
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
            'name' => 'Onboarding HTTP Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => sprintf(
                'onboarding-http-operator-%s@educore.test',
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
     * @param  list<string>  $permissionNames
     */
    private function grantSinglePermissionRole(array $permissionNames): void
    {
        $roleId = UuidV7::generate();

        DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'manage-only-'.Str::lower(Str::random(6)),
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
