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
use Modules\HR\Models\BenefitProgram;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsAuthorizationRole;
use Tests\Support\GrantsSubscriptionFeature;
use Tests\TestCase;

final class BenefitProgramControllerTest extends TestCase
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

    public function test_index_lists_programs_for_current_tenant(): void
    {
        $this->createProgramFixture($this->tenantId, 'BPJS_KESEHATAN');

        $otherTenantId = UuidV7::generate();
        $this->createTenantFixture($otherTenantId);
        $this->createProgramFixture($otherTenantId, 'BPJS_KESEHATAN_OTHER');

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route('api.v1.hr.benefits.programs.index', [], false),
            );

        $response->assertOk();

        $codes = collect($response->json('data'))->pluck('code');

        $this->assertTrue($codes->contains('BPJS_KESEHATAN'));
        $this->assertFalse($codes->contains('BPJS_KESEHATAN_OTHER'));
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $response = $this
            ->withToken($this->issueToken())
            ->getJson(
                route('api.v1.hr.benefits.programs.index', [], false),
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_store_creates_new_program(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.benefits.programs.store', [], false),
                [
                    'code' => 'BPJS_KESEHATAN',
                    'name' => 'BPJS Kesehatan',
                    'category' => BenefitProgram::CATEGORY_STATUTORY,
                    'beneficiary_scope' => BenefitProgram::BENEFICIARY_SCOPE_EITHER,
                    'payroll_relevance' => BenefitProgram::PAYROLL_RELEVANCE_ELIGIBILITY_INPUT,
                ],
            );

        $response->assertCreated();

        $this->assertDatabaseHas('benefit_programs', [
            'tenant_id' => $this->tenantId,
            'code' => 'BPJS_KESEHATAN',
        ]);
    }

    public function test_store_is_forbidden_without_manage_permission(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.benefits.programs.store', [], false),
                [
                    'code' => 'BPJS_KESEHATAN',
                    'name' => 'BPJS Kesehatan',
                    'category' => BenefitProgram::CATEGORY_STATUTORY,
                    'beneficiary_scope' => BenefitProgram::BENEFICIARY_SCOPE_EITHER,
                    'payroll_relevance' => BenefitProgram::PAYROLL_RELEVANCE_ELIGIBILITY_INPUT,
                ],
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_store_rejects_duplicate_code_within_tenant(): void
    {
        $this->createProgramFixture($this->tenantId, 'BPJS_KESEHATAN');

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route('api.v1.hr.benefits.programs.store', [], false),
                [
                    'code' => 'BPJS_KESEHATAN',
                    'name' => 'BPJS Kesehatan Duplikat',
                    'category' => BenefitProgram::CATEGORY_STATUTORY,
                    'beneficiary_scope' => BenefitProgram::BENEFICIARY_SCOPE_EITHER,
                    'payroll_relevance' => BenefitProgram::PAYROLL_RELEVANCE_ELIGIBILITY_INPUT,
                ],
            );

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJsonValidationErrors(['code']);
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
            'name' => 'Benefit Program Controller Tenant',
            'subdomain' => sprintf(
                'benefit-program-%s',
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
            'name' => 'Benefit Program Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => sprintf(
                'benefit-program-operator-%s@educore.test',
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

    private function createProgramFixture(
        string $tenantId,
        string $code,
    ): void {
        DB::table('benefit_programs')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => 'Program Uji ' . $code,
            'category' => BenefitProgram::CATEGORY_STATUTORY,
            'beneficiary_scope' => BenefitProgram::BENEFICIARY_SCOPE_EITHER,
            'payroll_relevance' => BenefitProgram::PAYROLL_RELEVANCE_ELIGIBILITY_INPUT,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
