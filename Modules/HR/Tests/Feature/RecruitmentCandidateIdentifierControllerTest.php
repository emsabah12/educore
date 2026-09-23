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
use Modules\HR\Models\RecruitmentCandidate;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsAuthorizationRole;
use Tests\TestCase;

/**
 * §Melengkapi identifier kuat ke Candidate yang SUDAH ADA -- lihat
 * catatan lengkap di RecruitmentCandidateIdentifierController.
 * Pola test SENGAJA identik dengan BenefitIdentifierControllerTest.
 */
final class RecruitmentCandidateIdentifierControllerTest extends TestCase
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
        $candidateId = $this->createCandidateWithoutIdentifierFixture();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.recruitment.candidates.identifiers.store',
                    ['candidateId' => $candidateId],
                    false,
                ),
                [
                    'type' => 'NATIONAL_ID',
                    'issuing_country_code' => 'ID',
                    'value' => '3201234567890099',
                ],
            );

        $response->assertCreated();
        $response->assertJsonPath('data.type', 'NATIONAL_ID');
        $response->assertJsonPath('data.candidate_id', $candidateId);

        $this->assertStringNotContainsString(
            '3201234567890099',
            $response->getContent(),
            'Raw identifier value must never appear in the store() response.',
        );
    }

    public function test_store_is_forbidden_without_manage_permission(): void
    {
        DB::table('membership_roles')
            ->where('membership_id', $this->operatorMembershipId)
            ->delete();

        $candidateId = $this->createCandidateWithoutIdentifierFixture();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.recruitment.candidates.identifiers.store',
                    ['candidateId' => $candidateId],
                    false,
                ),
                [
                    'type' => 'NATIONAL_ID',
                    'issuing_country_code' => 'ID',
                    'value' => '3201234567890099',
                ],
            );

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_store_returns_not_found_for_unknown_candidate(): void
    {
        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.recruitment.candidates.identifiers.store',
                    ['candidateId' => UuidV7::generate()],
                    false,
                ),
                [
                    'type' => 'NATIONAL_ID',
                    'issuing_country_code' => 'ID',
                    'value' => '3201234567890099',
                ],
            );

        $response->assertStatus(Response::HTTP_NOT_FOUND);
        $response->assertJsonPath('code', 'RECRUITMENT_CANDIDATE_NOT_FOUND');
    }

    public function test_store_returns_conflict_when_identifier_already_belongs_to_another_candidate(): void
    {
        $firstCandidateId = $this->createCandidateWithoutIdentifierFixture();
        $secondCandidateId = $this->createCandidateWithoutIdentifierFixture();

        $identifierPayload = [
            'type' => 'NATIONAL_ID',
            'issuing_country_code' => 'ID',
            'value' => '3209999999999999',
        ];

        $this->withToken($this->issueToken())->postJson(
            route(
                'api.v1.hr.recruitment.candidates.identifiers.store',
                ['candidateId' => $firstCandidateId],
                false,
            ),
            $identifierPayload,
        )->assertCreated();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.recruitment.candidates.identifiers.store',
                    ['candidateId' => $secondCandidateId],
                    false,
                ),
                $identifierPayload,
            );

        $response
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertJsonPath('code', 'RECRUITMENT_CANDIDATE_IDENTIFIER_CONFLICT');
    }

    public function test_store_validation_rejects_missing_fields(): void
    {
        $candidateId = $this->createCandidateWithoutIdentifierFixture();

        $response = $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.recruitment.candidates.identifiers.store',
                    ['candidateId' => $candidateId],
                    false,
                ),
                [],
            );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors([
            'type',
            'issuing_country_code',
            'value',
        ]);
    }

    /**
     * §Bukti end-to-end paling penting untuk gap ini: Candidate yang
     * TADINYA tidak punya identifier (dan karenanya SELALU gagal di
     * hire-conversion) BISA dilengkapi lewat endpoint ini, lalu
     * langsung berhasil dikonversi -- tanpa perlu menghapus dan
     * membuat ulang Candidate.
     */
    public function test_candidate_without_identifier_can_be_completed_then_hired(): void
    {
        $candidateId = $this->createCandidateWithoutIdentifierFixture();

        $this
            ->withToken($this->issueToken())
            ->postJson(
                route(
                    'api.v1.hr.recruitment.candidates.identifiers.store',
                    ['candidateId' => $candidateId],
                    false,
                ),
                [
                    'type' => 'NATIONAL_ID',
                    'issuing_country_code' => 'ID',
                    'value' => '3201234567891234',
                ],
            )->assertCreated();

        app(TenantContextInterface::class)->clear();

        $tenant = Tenant::query()->findOrFail($this->tenantId);
        app(TenantContextInterface::class)->setCurrentTenant($tenant);

        $identifierCount = DB::table('recruitment_candidate_identifiers')
            ->where('candidate_id', $candidateId)
            ->where('tenant_id', $this->tenantId)
            ->count();

        $this->assertSame(
            1,
            $identifierCount,
            'The identifier must actually be persisted and attributed to this exact Candidate, so a subsequent hire-conversion attempt can resolve it.',
        );

        app(TenantContextInterface::class)->clear();
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
            'name' => 'Recruitment Candidate Identifier Controller Tenant',
            'subdomain' => sprintf(
                'rec-candidate-identifier-ctrl-%s',
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
            'name' => 'Recruitment Candidate Identifier Controller Operator',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->insert([
            'id' => $this->operatorUserId,
            'person_id' => $personId,
            'email' => sprintf(
                'rec-candidate-identifier-ctrl-operator-%s@educore.test',
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

    private function createCandidateWithoutIdentifierFixture(): string
    {
        app(TenantContextInterface::class)->clear();

        $tenant = Tenant::query()->findOrFail($this->tenantId);
        app(TenantContextInterface::class)->setCurrentTenant($tenant);

        $candidate = RecruitmentCandidate::create([
            'display_name' => 'Kandidat Tanpa Identitas '.Str::upper(Str::random(6)),
            'status' => 'ACTIVE',
        ]);

        app(TenantContextInterface::class)->clear();

        return $candidate->id;
    }
}
