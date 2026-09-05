<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Models\Position;
use Modules\HR\Models\RecruitmentApplication;
use Modules\HR\Models\RecruitmentApplicationStage;
use Modules\HR\Models\RecruitmentCandidate;
use Modules\HR\Models\RecruitmentVacancy;
use Modules\HR\Models\RecruitmentVacancyStage;
use Tests\TestCase;

final class RecruitmentApplicationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantAId;
    private string $tenantBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantAId = $this->createTenant();
        $this->tenantBId = $this->createTenant();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_application_can_be_created_with_default_submitted_status(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $vacancyId = $this->createVacancy();
        $candidateId = $this->createCandidate();

        $application = RecruitmentApplication::create([
            'vacancy_id' => $vacancyId,
            'candidate_id' => $candidateId,
            'submitted_at' => now(),
        ]);

        $this->assertTrue(Str::isUuid($application->id));
        $this->assertSame(RecruitmentApplication::STATUS_SUBMITTED, $application->status);
    }

    public function test_database_rejects_duplicate_application_for_same_vacancy_and_candidate(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $vacancyId = $this->createVacancy();
        $candidateId = $this->createCandidate();

        RecruitmentApplication::create([
            'vacancy_id' => $vacancyId,
            'candidate_id' => $candidateId,
            'submitted_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        RecruitmentApplication::create([
            'vacancy_id' => $vacancyId,
            'candidate_id' => $candidateId,
            'submitted_at' => now(),
        ]);
    }

    public function test_same_candidate_can_apply_to_different_vacancies(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $candidateId = $this->createCandidate();
        $firstVacancyId = $this->createVacancy('VAC-APP-1');
        $secondVacancyId = $this->createVacancy('VAC-APP-2');

        RecruitmentApplication::create([
            'vacancy_id' => $firstVacancyId,
            'candidate_id' => $candidateId,
            'submitted_at' => now(),
        ]);

        $second = RecruitmentApplication::create([
            'vacancy_id' => $secondVacancyId,
            'candidate_id' => $candidateId,
            'submitted_at' => now(),
        ]);

        $this->assertSame($secondVacancyId, $second->vacancy_id);
    }

    public function test_check_constraint_rejects_unknown_status(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $vacancyId = $this->createVacancy();
        $candidateId = $this->createCandidate();

        $this->expectException(QueryException::class);

        RecruitmentApplication::create([
            'vacancy_id' => $vacancyId,
            'candidate_id' => $candidateId,
            'submitted_at' => now(),
            'status' => 'UNKNOWN',
        ]);
    }

    public function test_check_constraint_rejects_finalized_at_with_non_final_status(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $vacancyId = $this->createVacancy();
        $candidateId = $this->createCandidate();

        $this->expectException(QueryException::class);

        RecruitmentApplication::create([
            'vacancy_id' => $vacancyId,
            'candidate_id' => $candidateId,
            'submitted_at' => now(),
            'status' => RecruitmentApplication::STATUS_IN_PROCESS,
            'finalized_at' => now(),
        ]);
    }

    public function test_composite_foreign_key_rejects_candidate_from_another_tenant(): void
    {
        $this->activateTenantContext($this->tenantBId);
        $candidateFromTenantB = $this->createCandidate();

        $this->activateTenantContext($this->tenantAId);
        $vacancyId = $this->createVacancy();

        $this->expectException(QueryException::class);

        RecruitmentApplication::create([
            'vacancy_id' => $vacancyId,
            'candidate_id' => $candidateFromTenantB,
            'submitted_at' => now(),
        ]);
    }

    public function test_application_stage_can_be_created_and_relation_works(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $vacancyId = $this->createVacancy();
        $vacancyStageId = $this->createVacancyStage($vacancyId);
        $candidateId = $this->createCandidate();

        $application = RecruitmentApplication::create([
            'vacancy_id' => $vacancyId,
            'candidate_id' => $candidateId,
            'submitted_at' => now(),
        ]);

        $stage = RecruitmentApplicationStage::create([
            'application_id' => $application->id,
            'vacancy_stage_id' => $vacancyStageId,
        ]);

        $this->assertSame(RecruitmentApplicationStage::STATUS_PENDING, $stage->status);
        $this->assertCount(1, $application->stages);
    }

    public function test_database_rejects_duplicate_stage_for_same_application_and_vacancy_stage(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $vacancyId = $this->createVacancy();
        $vacancyStageId = $this->createVacancyStage($vacancyId);
        $candidateId = $this->createCandidate();

        $application = RecruitmentApplication::create([
            'vacancy_id' => $vacancyId,
            'candidate_id' => $candidateId,
            'submitted_at' => now(),
        ]);

        RecruitmentApplicationStage::create([
            'application_id' => $application->id,
            'vacancy_stage_id' => $vacancyStageId,
        ]);

        $this->expectException(QueryException::class);

        RecruitmentApplicationStage::create([
            'application_id' => $application->id,
            'vacancy_stage_id' => $vacancyStageId,
        ]);
    }

    public function test_check_constraint_rejects_unknown_stage_status(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $vacancyId = $this->createVacancy();
        $vacancyStageId = $this->createVacancyStage($vacancyId);
        $candidateId = $this->createCandidate();

        $application = RecruitmentApplication::create([
            'vacancy_id' => $vacancyId,
            'candidate_id' => $candidateId,
            'submitted_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        RecruitmentApplicationStage::create([
            'application_id' => $application->id,
            'vacancy_stage_id' => $vacancyStageId,
            'status' => 'UNKNOWN',
        ]);
    }

    private function activateTenantContext(string $tenantId): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($tenantId);

        app(TenantContextInterface::class)->setCurrentTenant($tenant);
    }

    private function createTenant(): string
    {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Application Tenant',
            'subdomain' => sprintf(
                'application-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createCandidate(): string
    {
        return RecruitmentCandidate::create([
            'display_name' => 'Kandidat Uji Application ' . Str::random(6),
        ])->id;
    }

    private function createVacancy(?string $code = null): string
    {
        $positionId = Position::create([
            'code' => 'POS-' . Str::upper(Str::random(6)),
            'name' => 'Posisi Uji Application',
            'is_active' => true,
        ])->id;

        $tenantId = (string) app(TenantContextInterface::class)->getCurrentTenantId();

        $organizationId = UuidV7::generate();
        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $tenantId,
            'name' => 'Application Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $personId = UuidV7::generate();
        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Application Fixture Actor',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $membershipId = UuidV7::generate();
        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return RecruitmentVacancy::create([
            'code' => $code ?? ('VAC-APP-' . Str::upper(Str::random(6))),
            'title' => 'Guru Matematika',
            'position_id' => $positionId,
            'organization_id' => $organizationId,
            'requested_headcount' => 1,
            'created_by_membership_id' => $membershipId,
        ])->id;
    }

    private function createVacancyStage(string $vacancyId): string
    {
        return RecruitmentVacancyStage::create([
            'vacancy_id' => $vacancyId,
            'code' => 'ADMIN_SCREEN',
            'name' => 'Seleksi Administrasi',
            'sequence' => 1,
        ])->id;
    }
}
