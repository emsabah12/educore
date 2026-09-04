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
use Modules\HR\Models\RecruitmentVacancy;
use Modules\HR\Models\RecruitmentVacancyStage;
use Tests\TestCase;

final class RecruitmentVacancyStagePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantAId;
    private string $tenantBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantAId = $this->createTenant('Vacancy Stage Tenant A');
        $this->tenantBId = $this->createTenant('Vacancy Stage Tenant B');
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_stage_can_be_created_with_default_flags(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $vacancyId = $this->createVacancy($this->tenantAId, 'VAC-A');

        $stage = RecruitmentVacancyStage::create([
            'vacancy_id' => $vacancyId,
            'code' => 'ADMIN_SCREEN',
            'name' => 'Seleksi Administrasi',
            'sequence' => 1,
        ]);

        $this->assertTrue(Str::isUuid($stage->id));
        $this->assertTrue($stage->is_required);
        $this->assertTrue($stage->is_active);
    }

    public function test_stage_code_must_be_unique_per_vacancy(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $vacancyId = $this->createVacancy($this->tenantAId, 'VAC-B');

        RecruitmentVacancyStage::create([
            'vacancy_id' => $vacancyId,
            'code' => 'TEST',
            'name' => 'Tes Tertulis',
            'sequence' => 1,
        ]);

        $this->expectException(QueryException::class);

        RecruitmentVacancyStage::create([
            'vacancy_id' => $vacancyId,
            'code' => 'TEST',
            'name' => 'Tes Tertulis Duplikat',
            'sequence' => 2,
        ]);
    }

    public function test_stage_sequence_must_be_unique_per_vacancy(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $vacancyId = $this->createVacancy($this->tenantAId, 'VAC-C');

        RecruitmentVacancyStage::create([
            'vacancy_id' => $vacancyId,
            'code' => 'TEST',
            'name' => 'Tes Tertulis',
            'sequence' => 1,
        ]);

        $this->expectException(QueryException::class);

        RecruitmentVacancyStage::create([
            'vacancy_id' => $vacancyId,
            'code' => 'INTERVIEW',
            'name' => 'Wawancara',
            'sequence' => 1,
        ]);
    }

    public function test_same_code_is_allowed_across_different_vacancies(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $firstVacancyId = $this->createVacancy($this->tenantAId, 'VAC-D1');
        $secondVacancyId = $this->createVacancy($this->tenantAId, 'VAC-D2');

        RecruitmentVacancyStage::create([
            'vacancy_id' => $firstVacancyId,
            'code' => 'INTERVIEW',
            'name' => 'Wawancara',
            'sequence' => 1,
        ]);

        $stage = RecruitmentVacancyStage::create([
            'vacancy_id' => $secondVacancyId,
            'code' => 'INTERVIEW',
            'name' => 'Wawancara',
            'sequence' => 1,
        ]);

        $this->assertSame($secondVacancyId, $stage->vacancy_id);
    }

    public function test_composite_foreign_key_rejects_vacancy_from_another_tenant(): void
    {
        $this->activateTenantContext($this->tenantBId);
        $vacancyFromTenantB = $this->createVacancy($this->tenantBId, 'VAC-CROSS');

        $this->activateTenantContext($this->tenantAId);

        $this->expectException(QueryException::class);

        RecruitmentVacancyStage::create([
            'vacancy_id' => $vacancyFromTenantB,
            'code' => 'TEST',
            'name' => 'Tes Tertulis',
            'sequence' => 1,
        ]);
    }

    public function test_vacancy_stages_relation_returns_stages_ordered_by_sequence(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $vacancyId = $this->createVacancy($this->tenantAId, 'VAC-ORDER');

        RecruitmentVacancyStage::create([
            'vacancy_id' => $vacancyId,
            'code' => 'INTERVIEW',
            'name' => 'Wawancara',
            'sequence' => 2,
        ]);
        RecruitmentVacancyStage::create([
            'vacancy_id' => $vacancyId,
            'code' => 'ADMIN_SCREEN',
            'name' => 'Seleksi Administrasi',
            'sequence' => 1,
        ]);

        /** @var RecruitmentVacancy $vacancy */
        $vacancy = RecruitmentVacancy::query()->findOrFail($vacancyId);

        $this->assertSame(
            ['ADMIN_SCREEN', 'INTERVIEW'],
            $vacancy->stages->pluck('code')->all(),
        );
    }

    private function activateTenantContext(string $tenantId): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($tenantId);

        app(TenantContextInterface::class)->setCurrentTenant($tenant);
    }

    private function createTenant(string $name): string
    {
        $tenantId = UuidV7::generate();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => $name,
            'subdomain' => sprintf(
                'vacancy-stage-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createVacancy(string $tenantId, string $code): string
    {
        $positionId = Position::create([
            'code' => 'POS-' . Str::upper(Str::random(6)),
            'name' => 'Posisi Uji Vacancy Stage',
            'is_active' => true,
        ])->id;

        $organizationId = UuidV7::generate();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $tenantId,
            'name' => 'Vacancy Stage Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Vacancy Stage Fixture Actor',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return RecruitmentVacancy::create([
            'code' => $code,
            'title' => 'Guru Matematika',
            'position_id' => $positionId,
            'organization_id' => $organizationId,
            'requested_headcount' => 1,
            'created_by_membership_id' => $membershipId,
        ])->id;
    }
}
