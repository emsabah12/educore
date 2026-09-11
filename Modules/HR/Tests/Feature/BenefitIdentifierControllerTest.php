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
use Modules\HR\Models\EmployeeBenefitParticipation;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsAuthorizationRole;
use Tests\Support\GrantsSubscriptionFeature;
use Tests\TestCase;

final class BenefitIdentifierControllerTest extends TestCase
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

    public function test_store_registers_identifier_without_leaking_value_in_response(): void
    {
        $participationId = $this->createParticipationFixture();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.benefit-participations.identifiers.store',
                    ['participationId' => $participationId],
                    false,
                ),
                [
                    'identifier_type' => 'BPJS_KESEHATAN_NUMBER',
                    'value' => '0001234567890',
                ],
            );

        $response->assertCreated();
        $response->assertJsonPath('data.identifier_type', 'BPJS_KESEHATAN_NUMBER');

        $this->assertStringNotContainsString(
            '0001234567890',
            $response->getContent(),
            'Raw identifier value must never appear in the store() response.',
        );
    }

    public function test_store_is_forbidden_without_manage_permission(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $participationId = $this->createParticipationFixture();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.benefit-participations.identifiers.store',
                    ['participationId' => $participationId],
                    false,
                ),
                [
                    'identifier_type' => 'BPJS_KESEHATAN_NUMBER',
                    'value' => '0001234567890',
                ],
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_store_returns_not_found_for_unknown_participation(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.benefit-participations.identifiers.store',
                    ['participationId' => UuidV7::generate()],
                    false,
                ),
                [
                    'identifier_type' => 'BPJS_KESEHATAN_NUMBER',
                    'value' => '0001234567890',
                ],
            );

        $response->assertStatus(Response::HTTP_NOT_FOUND);
    }

    public function test_index_returns_decrypted_value_and_requires_separate_view_permission(): void
    {
        $participationId = $this->createParticipationFixture();

        $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.benefit-participations.identifiers.store',
                    ['participationId' => $participationId],
                    false,
                ),
                [
                    'identifier_type' => 'BPJS_KESEHATAN_NUMBER',
                    'value' => '0001234567890',
                ],
            )->assertCreated();

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.hr.benefit-participations.identifiers.index',
                    ['participationId' => $participationId],
                    false,
                ),
            );

        $response->assertOk();
        $response->assertJsonPath('data.0.value', '0001234567890');
    }

    public function test_index_is_forbidden_without_view_permission(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $participationId = $this->createParticipationFixture();

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route(
                    'api.v1.hr.benefit-participations.identifiers.index',
                    ['participationId' => $participationId],
                    false,
                ),
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
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
            'name' => 'Benefit Identifier Controller Tenant',
            'subdomain' => sprintf(
                'benefit-identifier-ctrl-%s',
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
            'name' => 'Benefit Identifier Controller Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => sprintf(
                'benefit-identifier-ctrl-operator-%s@educore.test',
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

    private function createParticipationFixture(): string
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

        $employmentId = $this->createActiveEmploymentFixture();

        $participation = EmployeeBenefitParticipation::create([
            'employment_id' => $employmentId,
            'benefit_program_id' => $program->id,
            'status' => EmployeeBenefitParticipation::STATUS_ELIGIBLE,
            'effective_from' => '2026-01-01',
        ]);

        app(TenantContextInterface::class)->clear();

        return $participation->id;
    }

    private function createActiveEmploymentFixture(): string
    {
        $employeePersonId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $employeePersonId,
            'name' => 'Benefit Identifier Fixture Employee',
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
