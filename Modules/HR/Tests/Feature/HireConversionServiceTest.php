<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Person\Contracts\PersonIdentifierRepositoryInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Contracts\RecruitmentCandidateIdentifierRepositoryInterface;
use Modules\HR\Exceptions\RecruitmentLifecycleException;
use Modules\HR\Models\Employment;
use Modules\HR\Models\OnboardingCase;
use Modules\HR\Models\Position;
use Modules\HR\Models\RecruitmentApplication;
use Modules\HR\Models\RecruitmentCandidate;
use Modules\HR\Models\RecruitmentHireConversion;
use Modules\HR\Services\HireConversionService;
use Modules\HR\Services\OnboardingCaseLifecycleService;
use Modules\HR\Services\RecruitmentApplicationLifecycleService;
use Modules\HR\Services\RecruitmentVacancyLifecycleService;
use Tests\TestCase;

final class HireConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    private HireConversionService $service;
    private RecruitmentVacancyLifecycleService $vacancyService;
    private RecruitmentApplicationLifecycleService $applicationService;
    private RecruitmentCandidateIdentifierRepositoryInterface $identifierRepository;
    private string $tenantId;
    private string $actorMembershipId;
    private string $employmentTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(HireConversionService::class);
        $this->vacancyService = new RecruitmentVacancyLifecycleService();
        $this->applicationService = new RecruitmentApplicationLifecycleService();
        $this->identifierRepository = app(RecruitmentCandidateIdentifierRepositoryInterface::class);

        $this->tenantId = $this->createTenant();
        $this->activateTenantContext($this->tenantId);
        $this->actorMembershipId = $this->createMembership();
        $this->employmentTypeId = $this->createEmploymentType();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_convert_creates_person_membership_employee_and_planned_employment(): void
    {
        $applicationId = $this->createHiringApprovedApplication('3211111111111111');

        $conversion = $this->service->convert(
            tenantId: $this->tenantId,
            applicationId: $applicationId,
            employmentInput: ['employment_type_id' => $this->employmentTypeId, 'start_date' => '2026-09-01'],
            actorMembershipId: $this->actorMembershipId,
            confirmCreateNewPerson: true,
        );

        $this->assertSame(RecruitmentHireConversion::CONVERSION_SUCCEEDED, $conversion->conversion_status);
        $this->assertSame(RecruitmentHireConversion::RESOLUTION_CREATE_NEW_CONFIRMED, $conversion->resolution_status);
        $this->assertNotNull($conversion->person_id);
        $this->assertNotNull($conversion->employee_id);

        $employment = Employment::query()->findOrFail($conversion->employment_id);
        $this->assertSame(Employment::STATUS_PLANNED, $employment->status);

        $application = RecruitmentApplication::query()->findOrFail($applicationId);
        $this->assertSame(RecruitmentApplication::STATUS_HIRED, $application->status);

        $candidate = RecruitmentCandidate::query()->findOrFail($application->candidate_id);
        $this->assertSame($conversion->person_id, $candidate->person_id);
    }

    public function test_convert_reuses_existing_person_when_strong_identifier_matches(): void
    {
        $existingPersonId = $this->createPersonWithIdentifier('NATIONAL_ID', 'ID', '3212222222222222');
        $applicationId = $this->createHiringApprovedApplication('3212222222222222');

        $conversion = $this->service->convert(
            tenantId: $this->tenantId,
            applicationId: $applicationId,
            employmentInput: ['employment_type_id' => $this->employmentTypeId, 'start_date' => '2026-09-01'],
            actorMembershipId: $this->actorMembershipId,
        );

        $this->assertSame(RecruitmentHireConversion::RESOLUTION_MATCHED_EXISTING, $conversion->resolution_status);
        $this->assertSame($existingPersonId, $conversion->person_id);
    }

    public function test_convert_is_idempotent_on_repeated_call(): void
    {
        $applicationId = $this->createHiringApprovedApplication('3213333333333333');

        $first = $this->service->convert(
            tenantId: $this->tenantId,
            applicationId: $applicationId,
            employmentInput: ['employment_type_id' => $this->employmentTypeId, 'start_date' => '2026-09-01'],
            actorMembershipId: $this->actorMembershipId,
            confirmCreateNewPerson: true,
        );

        $second = $this->service->convert(
            tenantId: $this->tenantId,
            applicationId: $applicationId,
            employmentInput: ['employment_type_id' => $this->employmentTypeId, 'start_date' => '2026-09-01'],
            actorMembershipId: $this->actorMembershipId,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->employment_id, $second->employment_id);
        $this->assertSame(
            1,
            Employment::query()->where('employee_id', $first->employee_id)->count(),
        );
    }

    public function test_convert_rejects_application_not_hiring_approved(): void
    {
        $vacancyId = $this->createOpenVacancy();
        $candidateId = $this->createCandidateWithIdentifier('3214444444444444');
        $applicationId = $this->applicationService->submitApplication($this->tenantId, $vacancyId, $candidateId)->id;

        $this->expectException(RecruitmentLifecycleException::class);
        $this->expectExceptionMessageMatches('/must be HIRING_APPROVED/');

        $this->service->convert(
            tenantId: $this->tenantId,
            applicationId: $applicationId,
            employmentInput: ['employment_type_id' => $this->employmentTypeId, 'start_date' => '2026-09-01'],
            actorMembershipId: $this->actorMembershipId,
        );
    }

    public function test_convert_requires_confirmation_when_identity_is_unresolved(): void
    {
        $applicationId = $this->createHiringApprovedApplication('3215555555555555');

        $this->expectException(RecruitmentLifecycleException::class);
        $this->expectExceptionMessageMatches('/UNRESOLVED/');

        $this->service->convert(
            tenantId: $this->tenantId,
            applicationId: $applicationId,
            employmentInput: ['employment_type_id' => $this->employmentTypeId, 'start_date' => '2026-09-01'],
            actorMembershipId: $this->actorMembershipId,
            confirmCreateNewPerson: false,
        );

        $this->assertDatabaseHas('recruitment_hire_conversions', [
            'application_id' => $applicationId,
            'conversion_status' => RecruitmentHireConversion::CONVERSION_PENDING,
        ]);
    }

    public function test_convert_rejects_candidate_without_any_strong_identifier(): void
    {
        $vacancyId = $this->createOpenVacancy();
        $candidateId = RecruitmentCandidate::create([
            'display_name' => 'Kandidat Tanpa Identifier',
        ])->id;

        $applicationId = $this->applicationService->submitApplication($this->tenantId, $vacancyId, $candidateId)->id;
        $this->applicationService->startProcessing($this->tenantId, $applicationId);
        $this->applicationService->approveForHiring($this->tenantId, $applicationId, $this->actorMembershipId);

        $this->expectException(RecruitmentLifecycleException::class);
        $this->expectExceptionMessageMatches('/has no strong identifier/');

        $this->service->convert(
            tenantId: $this->tenantId,
            applicationId: $applicationId,
            employmentInput: ['employment_type_id' => $this->employmentTypeId, 'start_date' => '2026-09-01'],
            actorMembershipId: $this->actorMembershipId,
            confirmCreateNewPerson: true,
        );
    }

    public function test_convert_links_onboarding_case_to_employee_and_employment(): void
    {
        $applicationId = $this->createHiringApprovedApplication('3216666666666666');
        $onboardingCase = (new OnboardingCaseLifecycleService())->createCase($this->tenantId, $applicationId);

        $conversion = $this->service->convert(
            tenantId: $this->tenantId,
            applicationId: $applicationId,
            employmentInput: ['employment_type_id' => $this->employmentTypeId, 'start_date' => '2026-09-01'],
            actorMembershipId: $this->actorMembershipId,
            confirmCreateNewPerson: true,
        );

        $refreshedCase = OnboardingCase::query()->findOrFail($onboardingCase->id);
        $this->assertSame($conversion->employee_id, $refreshedCase->employee_id);
        $this->assertSame($conversion->employment_id, $refreshedCase->employment_id);
    }

    private function createHiringApprovedApplication(string $nationalId): string
    {
        $vacancyId = $this->createOpenVacancy();
        $candidateId = $this->createCandidateWithIdentifier($nationalId);

        $application = $this->applicationService->submitApplication($this->tenantId, $vacancyId, $candidateId);
        $this->applicationService->startProcessing($this->tenantId, $application->id);
        $this->applicationService->approveForHiring($this->tenantId, $application->id, $this->actorMembershipId);

        return $application->id;
    }

    private function createCandidateWithIdentifier(string $nationalId): string
    {
        $candidateId = RecruitmentCandidate::create([
            'display_name' => 'Kandidat Uji Hire Conversion ' . Str::random(6),
        ])->id;

        $this->identifierRepository->store(
            tenantId: $this->tenantId,
            candidateId: $candidateId,
            type: 'NATIONAL_ID',
            issuingCountryCode: 'ID',
            rawValue: $nationalId,
        );

        return $candidateId;
    }

    private function createPersonWithIdentifier(
        string $type,
        string $issuingCountryCode,
        string $rawValue,
    ): string {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Person Fixture Sudah Ada ' . Str::random(6),
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(PersonIdentifierRepositoryInterface::class)
            ->store($personId, $type, $issuingCountryCode, $rawValue);

        return $personId;
    }

    private function createOpenVacancy(): string
    {
        $vacancy = $this->vacancyService->createDraft($this->tenantId, [
            'code' => 'VAC-HIRE-' . Str::upper(Str::random(6)),
            'title' => 'Guru Matematika',
            'position_id' => $this->createPosition(),
            'organization_id' => $this->createOrganization(),
            'requested_headcount' => 1,
            'created_by_membership_id' => $this->actorMembershipId,
        ]);

        $this->vacancyService->submit($this->tenantId, $vacancy->id);
        $this->vacancyService->approve($this->tenantId, $vacancy->id, $this->actorMembershipId);

        return $this->vacancyService->open($this->tenantId, $vacancy->id)->id;
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
            'name' => 'Hire Conversion Service Tenant',
            'subdomain' => sprintf(
                'hire-conversion-svc-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createMembership(): string
    {
        $personId = UuidV7::generate();
        $membershipId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Hire Conversion Service Fixture Actor',
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

    private function createPosition(): string
    {
        return Position::create([
            'code' => 'POS-' . Str::upper(Str::random(6)),
            'name' => 'Guru Matematika',
            'is_active' => true,
        ])->id;
    }

    private function createOrganization(): string
    {
        $organizationId = UuidV7::generate();

        DB::table('organizations')->insert([
            'id' => $organizationId,
            'tenant_id' => $this->tenantId,
            'name' => 'Hire Conversion Service Fixture Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $organizationId;
    }

    private function createEmploymentType(): string
    {
        $employmentTypeId = UuidV7::generate();

        DB::table('employment_types')->insert([
            'id' => $employmentTypeId,
            'tenant_id' => $this->tenantId,
            'code' => 'TETAP-' . Str::upper(Str::random(6)),
            'name' => 'Pegawai Tetap',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employmentTypeId;
    }
}
