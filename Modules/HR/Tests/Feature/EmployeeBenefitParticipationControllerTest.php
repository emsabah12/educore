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
use Modules\HR\Models\BenefitProgram;
use Modules\HR\Models\Employment;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsAuthorizationRole;
use Tests\Support\GrantsSubscriptionFeature;
use Tests\TestCase;

final class EmployeeBenefitParticipationControllerTest extends TestCase
{
    use RefreshDatabase;
    use GrantsAuthorizationRole;
    use GrantsSubscriptionFeature;

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
        $this->grantTenantFeature($this->tenantId, 'hr_module');
        $this->createOperatorFixture();

        $this->grantRole(
            $this->operatorMembershipId,
            HrAuthorizationCatalogSeeder::HR_OFFICER_ROLE,
        );

        app(TenantContextInterface::class)->clear();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_store_creates_eligible_participation(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();
        $programId = $this->createProgramFixture()->id;

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.benefit-participations.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                [
                    'benefit_program_id' => $programId,
                    'effective_from' => '2026-01-01',
                ],
            );

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'ELIGIBLE');

        $this->assertDatabaseHas('employee_benefit_participations', [
            'employment_id' => $employmentId,
            'status' => 'ELIGIBLE',
        ]);
    }

    public function test_store_is_forbidden_without_manage_permission(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $employmentId = $this->createActiveEmploymentFixture();
        $programId = $this->createProgramFixture()->id;

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.benefit-participations.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                [
                    'benefit_program_id' => $programId,
                    'effective_from' => '2026-01-01',
                ],
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_index_lists_participations_for_employment(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();
        $programId = $this->createProgramFixture()->id;

        $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.benefit-participations.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                [
                    'benefit_program_id' => $programId,
                    'effective_from' => '2026-01-01',
                ],
            )->assertCreated();

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.hr.employments.benefit-participations.index',
                    ['employmentId' => $employmentId],
                    false,
                ),
            );

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_enroll_transitions_eligible_to_enrolled_using_authenticated_membership(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();
        $programId = $this->createProgramFixture()->id;

        $storeResponse = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.benefit-participations.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                [
                    'benefit_program_id' => $programId,
                    'effective_from' => '2026-01-01',
                ],
            );

        $participationId = $storeResponse->json('data.id');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.benefit-participations.enroll',
                    ['employmentId' => $employmentId, 'participationId' => $participationId],
                    false,
                ),
            );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'ENROLLED');
        $response->assertJsonPath('data.verified_by_membership_id', $this->operatorMembershipId);
    }

    public function test_enroll_is_forbidden_without_enroll_permission(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();
        $programId = $this->createProgramFixture()->id;

        $storeResponse = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.benefit-participations.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                [
                    'benefit_program_id' => $programId,
                    'effective_from' => '2026-01-01',
                ],
            );

        $participationId = $storeResponse->json('data.id');

        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.benefit-participations.enroll',
                    ['employmentId' => $employmentId, 'participationId' => $participationId],
                    false,
                ),
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_suspend_transitions_enrolled_to_suspended(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();
        $programId = $this->createProgramFixture()->id;

        $participationId = $this->createEnrolledParticipation($employmentId, $programId);

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.benefit-participations.suspend',
                    ['employmentId' => $employmentId, 'participationId' => $participationId],
                    false,
                ),
            );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'SUSPENDED');
    }

    public function test_reinstate_transitions_suspended_back_to_enrolled(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();
        $programId = $this->createProgramFixture()->id;

        $participationId = $this->createEnrolledParticipation($employmentId, $programId);

        $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.benefit-participations.suspend',
                    ['employmentId' => $employmentId, 'participationId' => $participationId],
                    false,
                ),
            )->assertOk();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.benefit-participations.reinstate',
                    ['employmentId' => $employmentId, 'participationId' => $participationId],
                    false,
                ),
            );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'ENROLLED');
    }

    public function test_end_closes_open_participation(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();
        $programId = $this->createProgramFixture()->id;

        $participationId = $this->createEnrolledParticipation($employmentId, $programId);

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.benefit-participations.end',
                    ['employmentId' => $employmentId, 'participationId' => $participationId],
                    false,
                ),
                ['end_date' => '2026-06-30'],
            );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'ENDED');
        $response->assertJsonPath('data.effective_to', '2026-06-30');
    }

    public function test_suspend_is_forbidden_without_manage_permission(): void
    {
        $employmentId = $this->createActiveEmploymentFixture();
        $programId = $this->createProgramFixture()->id;

        $participationId = $this->createEnrolledParticipation($employmentId, $programId);

        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.benefit-participations.suspend',
                    ['employmentId' => $employmentId, 'participationId' => $participationId],
                    false,
                ),
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    private function createEnrolledParticipation(
        string $employmentId,
        string $programId,
    ): string {
        $storeResponse = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.benefit-participations.store',
                    ['employmentId' => $employmentId],
                    false,
                ),
                [
                    'benefit_program_id' => $programId,
                    'effective_from' => '2026-01-01',
                ],
            );

        $participationId = $storeResponse->json('data.id');

        $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.employments.benefit-participations.enroll',
                    ['employmentId' => $employmentId, 'participationId' => $participationId],
                    false,
                ),
            )->assertOk();

        return $participationId;
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

    private function createTenantFixture(?string $tenantId = null): void
    {
        DB::table('tenants')->insert([
            'id' => $tenantId ?? $this->tenantId,
            'name' => 'Benefit Participation Controller Tenant',
            'subdomain' => sprintf(
                'benefit-participation-%s',
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
            'name' => 'Benefit Participation Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => sprintf(
                'benefit-participation-operator-%s@educore.test',
                Str::lower(Str::random(10)),
            ),
            'password' => bcrypt('secret123'),
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

    private function createProgramFixture(): BenefitProgram
    {
        app(TenantContextInterface::class)->clear();

        $tenant = Tenant::query()->findOrFail($this->tenantId);
        app(TenantContextInterface::class)->setCurrentTenant($tenant);

        $program = BenefitProgram::create([
            'code' => 'BPJS-' . Str::upper(Str::random(4)),
            'name' => 'BPJS Kesehatan',
            'category' => BenefitProgram::CATEGORY_STATUTORY,
            'beneficiary_scope' => BenefitProgram::BENEFICIARY_SCOPE_EITHER,
            'payroll_relevance' => BenefitProgram::PAYROLL_RELEVANCE_ELIGIBILITY_INPUT,
            'is_active' => true,
        ]);

        app(TenantContextInterface::class)->clear();

        return $program;
    }

    private function createActiveEmploymentFixture(): string
    {
        $employeePersonId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $employeePersonId,
            'name' => 'Benefit Participation Fixture Employee',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeMembershipId = UuidV7::generate();

        DB::table('memberships')->insert([
            'id' => $employeeMembershipId,
            'person_id' => $employeePersonId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = UuidV7::generate();

        DB::table('employees')->insert([
            'id' => $employeeId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $employeeMembershipId,
            'nip' => sprintf('NIP-%s', Str::upper(Str::random(8))),
            'jabatan' => 'GURU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employmentId = UuidV7::generate();

        DB::table('employments')->insert([
            'id' => $employmentId,
            'tenant_id' => $this->tenantId,
            'employee_id' => $employeeId,
            'status' => Employment::STATUS_ACTIVE,
            'start_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employmentId;
    }
}
