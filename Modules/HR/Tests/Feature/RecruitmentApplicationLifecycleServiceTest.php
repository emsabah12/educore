<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Exceptions\RecruitmentLifecycleException;
use Modules\HR\Models\Position;
use Modules\HR\Models\RecruitmentApplication;
use Modules\HR\Models\RecruitmentCandidate;
use Modules\HR\Models\RecruitmentVacancyStage;
use Modules\HR\Services\RecruitmentApplicationLifecycleService;
use Modules\HR\Services\RecruitmentVacancyLifecycleService;
use Tests\TestCase;

final class RecruitmentApplicationLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecruitmentApplicationLifecycleService $service;
    private RecruitmentVacancyLifecycleService $vacancyService;
    private string $tenantId;
    private string $membershipId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new RecruitmentApplicationLifecycleService();
        $this->vacancyService = new RecruitmentVacancyLifecycleService();
        $this->tenantId = $this->createTenant();
        $this->activateTenantContext($this->tenantId);
        $this->membershipId = $this->createMembership();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_submit_application_creates_submitted_application_with_snapshotted_active_stages(): void
    {
        $vacancyId = $this->createOpenVacancy();
        RecruitmentVacancyStage::create([
            'vacancy_id' => $vacancyId,
            'code' => 'ADMIN_SCREEN',
            'name' => 'Seleksi Administrasi',
            'sequence' => 1,
        ]);
        RecruitmentVacancyStage::create([
            'vacancy_id' => $vacancyId,
            'code' => 'INTERVIEW',
            'name' => 'Wawancara',
            'sequence' => 2,
            'is_active' => false,
        ]);
        $candidateId = $this->createCandidate();

        $application = $this->service->submitApplication(
            $this->tenantId,
            $vacancyId,
            $candidateId,
        );

        $this->assertSame(RecruitmentApplication::STATUS_SUBMITTED, $application->status);
        // Hanya stage AKTIF yang di-snapshot (INTERVIEW is_active=false
        // sengaja tidak ikut).
        $this->assertCount(1, $application->stages);
        $this->assertSame('ADMIN_SCREEN', $application->stages->first()->vacancyStage->code);
    }

    public function test_submit_application_rejects_non_open_vacancy(): void
    {
        $vacancyId = $this->createDraftVacancy();
        $candidateId = $this->createCandidate();

        $this->expectException(RecruitmentLifecycleException::class);
        $this->expectExceptionMessageMatches('/not accepting new Applications/');

        $this->service->submitApplication($this->tenantId, $vacancyId, $candidateId);
    }

    public function test_submit_application_rejects_duplicate_for_same_candidate_and_vacancy(): void
    {
        $vacancyId = $this->createOpenVacancy();
        $candidateId = $this->createCandidate();

        $this->service->submitApplication($this->tenantId, $vacancyId, $candidateId);

        $this->expectException(RecruitmentLifecycleException::class);
        $this->expectExceptionMessageMatches('/already applied/');

        $this->service->submitApplication($this->tenantId, $vacancyId, $candidateId);
    }

    public function test_start_processing_transitions_submitted_to_in_process(): void
    {
        $application = $this->createSubmittedApplication();

        $result = $this->service->startProcessing($this->tenantId, $application->id);

        $this->assertSame(RecruitmentApplication::STATUS_IN_PROCESS, $result->status);
    }

    public function test_reject_transitions_in_process_to_rejected_and_sets_finalized_at(): void
    {
        $application = $this->createSubmittedApplication();
        $this->service->startProcessing($this->tenantId, $application->id);

        $result = $this->service->reject($this->tenantId, $application->id);

        $this->assertSame(RecruitmentApplication::STATUS_REJECTED, $result->status);
        $this->assertNotNull($result->finalized_at);
    }

    public function test_withdraw_transitions_submitted_to_withdrawn(): void
    {
        $application = $this->createSubmittedApplication();

        $result = $this->service->withdraw($this->tenantId, $application->id);

        $this->assertSame(RecruitmentApplication::STATUS_WITHDRAWN, $result->status);
        $this->assertNotNull($result->finalized_at);
    }

    public function test_approve_for_hiring_transitions_in_process_to_hiring_approved_without_finalizing(): void
    {
        $application = $this->createSubmittedApplication();
        $this->service->startProcessing($this->tenantId, $application->id);

        $result = $this->service->approveForHiring($this->tenantId, $application->id);

        $this->assertSame(RecruitmentApplication::STATUS_HIRING_APPROVED, $result->status);
        // HIRING_APPROVED bukan status final — HIRED baru terjadi lewat
        // hire conversion (Fase E, belum dibangun).
        $this->assertNull($result->finalized_at);
    }

    public function test_withdraw_rejects_already_hiring_approved_application(): void
    {
        $application = $this->createSubmittedApplication();
        $this->service->startProcessing($this->tenantId, $application->id);
        $this->service->approveForHiring($this->tenantId, $application->id);

        $this->expectException(RecruitmentLifecycleException::class);
        $this->expectExceptionMessageMatches('/cannot be withdrawn from status \[HIRING_APPROVED\]/');

        $this->service->withdraw($this->tenantId, $application->id);
    }

    private function createSubmittedApplication(): RecruitmentApplication
    {
        $vacancyId = $this->createOpenVacancy();
        $candidateId = $this->createCandidate();

        return $this->service->submitApplication($this->tenantId, $vacancyId, $candidateId);
    }

    private function createOpenVacancy(): string
    {
        $vacancy = $this->vacancyService->createDraft($this->tenantId, [
            'code' => 'VAC-APPSVC-' . Str::upper(Str::random(6)),
            'title' => 'Guru Matematika',
            'position_id' => $this->createPosition(),
            'organization_id' => $this->createOrganization(),
            'requested_headcount' => 1,
            'created_by_membership_id' => $this->membershipId,
        ]);

        $this->vacancyService->submit($this->tenantId, $vacancy->id);
        $this->vacancyService->approve($this->tenantId, $vacancy->id, $this->membershipId);

        return $this->vacancyService->open($this->tenantId, $vacancy->id)->id;
    }

    private function createDraftVacancy(): string
    {
        return $this->vacancyService->createDraft($this->tenantId, [
            'code' => 'VAC-APPSVC-' . Str::upper(Str::random(6)),
            'title' => 'Guru Matematika',
            'position_id' => $this->createPosition(),
            'organization_id' => $this->createOrganization(),
            'requested_headcount' => 1,
            'created_by_membership_id' => $this->membershipId,
        ])->id;
    }

    private function createCandidate(): string
    {
        return RecruitmentCandidate::create([
            'display_name' => 'Kandidat Uji Application Service ' . Str::random(6),
        ])->id;
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
            'name' => 'Application Service Tenant',
            'subdomain' => sprintf(
                'application-svc-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createPosition(): string
    {
        return Position::create([
            'code' => 'POS-' . Str::upper(Str::random(6)),
            'name' => 'Posisi Uji Application Service',
            'is_active' => true,
        ])->id;
    }

    private function createOrganization(): string
    {
        $organizationId = UuidV7::generate();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'Application Service Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $organizationId;
    }

    private function createMembership(): string
    {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Application Service Fixture Actor',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $membershipId;
    }
}
