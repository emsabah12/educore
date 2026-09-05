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
use Modules\HR\Models\OnboardingCase;
use Modules\HR\Models\OnboardingTask;
use Modules\HR\Models\Position;
use Modules\HR\Models\RecruitmentApplication;
use Modules\HR\Models\RecruitmentCandidate;
use Modules\HR\Models\RecruitmentVacancy;
use Tests\TestCase;

final class OnboardingCasePersistenceTest extends TestCase
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

    public function test_case_can_be_created_with_default_not_started_status(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $applicationId = $this->createRecruitmentApplicationFixture();

        $case = OnboardingCase::create([
            'application_id' => $applicationId,
        ]);

        $this->assertTrue(Str::isUuid($case->id));
        $this->assertSame(OnboardingCase::STATUS_NOT_STARTED, $case->status);
        $this->assertNull($case->employee_id);
        $this->assertNull($case->employment_id);
    }

    public function test_database_rejects_duplicate_case_for_same_application(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $applicationId = $this->createRecruitmentApplicationFixture();

        OnboardingCase::create(['application_id' => $applicationId]);

        $this->expectException(QueryException::class);

        OnboardingCase::create(['application_id' => $applicationId]);
    }

    public function test_check_constraint_rejects_unknown_case_status(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $applicationId = $this->createRecruitmentApplicationFixture();

        $this->expectException(QueryException::class);

        OnboardingCase::create([
            'application_id' => $applicationId,
            'status' => 'UNKNOWN',
        ]);
    }

    public function test_composite_foreign_key_rejects_application_from_another_tenant(): void
    {
        $this->activateTenantContext($this->tenantBId);
        $applicationFromTenantB = $this->createRecruitmentApplicationFixture();

        $this->activateTenantContext($this->tenantAId);

        $this->expectException(QueryException::class);

        OnboardingCase::create(['application_id' => $applicationFromTenantB]);
    }

    public function test_task_can_be_created_with_default_pending_status(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $caseId = $this->createCase();

        $task = OnboardingTask::create([
            'onboarding_case_id' => $caseId,
            'code' => 'SUBMIT_ID_CARD',
            'title' => 'Kumpulkan KTP',
            'category' => 'DOCUMENT',
            'sequence' => 1,
        ]);

        $this->assertSame(OnboardingTask::STATUS_PENDING, $task->status);
        $this->assertNull($task->completed_at);
    }

    public function test_database_rejects_duplicate_task_code_for_same_case(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $caseId = $this->createCase();

        OnboardingTask::create([
            'onboarding_case_id' => $caseId,
            'code' => 'DUP',
            'title' => 'Tugas Pertama',
            'category' => 'ADMIN',
            'sequence' => 1,
        ]);

        $this->expectException(QueryException::class);

        OnboardingTask::create([
            'onboarding_case_id' => $caseId,
            'code' => 'DUP',
            'title' => 'Tugas Kedua',
            'category' => 'ADMIN',
            'sequence' => 2,
        ]);
    }

    public function test_check_constraint_rejects_unknown_task_status(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $caseId = $this->createCase();

        $this->expectException(QueryException::class);

        OnboardingTask::create([
            'onboarding_case_id' => $caseId,
            'code' => 'BAD_STATUS',
            'title' => 'Tugas Aneh',
            'category' => 'ADMIN',
            'sequence' => 1,
            'status' => 'UNKNOWN',
        ]);
    }

    public function test_check_constraint_rejects_completed_status_without_completed_at(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $caseId = $this->createCase();

        $this->expectException(QueryException::class);

        OnboardingTask::create([
            'onboarding_case_id' => $caseId,
            'code' => 'INCONSISTENT',
            'title' => 'Tugas Tidak Konsisten',
            'category' => 'ADMIN',
            'sequence' => 1,
            'status' => OnboardingTask::STATUS_COMPLETED,
            'completed_at' => null,
        ]);
    }

    public function test_case_tasks_relation_orders_by_sequence(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $caseId = $this->createCase();

        OnboardingTask::create([
            'onboarding_case_id' => $caseId,
            'code' => 'ORIENTATION',
            'title' => 'Orientasi',
            'category' => 'ORIENTATION',
            'sequence' => 2,
        ]);
        OnboardingTask::create([
            'onboarding_case_id' => $caseId,
            'code' => 'SUBMIT_ID_CARD',
            'title' => 'Kumpulkan KTP',
            'category' => 'DOCUMENT',
            'sequence' => 1,
        ]);

        /** @var OnboardingCase $case */
        $case = OnboardingCase::query()->findOrFail($caseId);

        $this->assertSame(
            ['SUBMIT_ID_CARD', 'ORIENTATION'],
            $case->tasks->pluck('code')->all(),
        );
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
            'name' => 'Onboarding Case Tenant',
            'subdomain' => sprintf(
                'onboarding-case-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createCase(): string
    {
        return OnboardingCase::create([
            'application_id' => $this->createRecruitmentApplicationFixture(),
        ])->id;
    }

    private function createRecruitmentApplicationFixture(): string
    {
        $tenantId = (string) app(TenantContextInterface::class)->getCurrentTenantId();

        $positionId = Position::create([
            'code' => 'POS-' . Str::upper(Str::random(6)),
            'name' => 'Posisi Uji Onboarding Case',
            'is_active' => true,
        ])->id;

        $organizationId = UuidV7::generate();
        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $tenantId,
            'name' => 'Onboarding Case Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $personId = UuidV7::generate();
        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Onboarding Case Fixture Actor',
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

        $vacancyId = RecruitmentVacancy::create([
            'code' => 'VAC-OB-' . Str::upper(Str::random(6)),
            'title' => 'Guru Matematika',
            'position_id' => $positionId,
            'organization_id' => $organizationId,
            'requested_headcount' => 1,
            'created_by_membership_id' => $membershipId,
        ])->id;

        $candidateId = RecruitmentCandidate::create([
            'display_name' => 'Kandidat Uji Onboarding Case ' . Str::random(6),
        ])->id;

        return RecruitmentApplication::create([
            'vacancy_id' => $vacancyId,
            'candidate_id' => $candidateId,
            'submitted_at' => now(),
        ])->id;
    }
}
