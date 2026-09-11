<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Exceptions\BenefitParticipationLifecycleException;
use Modules\HR\Models\BenefitProgram;
use Modules\HR\Models\Employment;
use Modules\HR\Models\EmployeeBenefitParticipation;
use Modules\HR\Services\BenefitParticipationService;
use Tests\TestCase;

final class BenefitParticipationServiceTest extends TestCase
{
    use RefreshDatabase;

    private BenefitParticipationService $service;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new BenefitParticipationService();
        $this->tenantId = $this->createTenant('Benefit Participation Service Tenant');
        $this->activateTenantContext($this->tenantId);
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // create
    // ---------------------------------------------------------------

    public function test_create_succeeds_for_self_beneficiary(): void
    {
        $employmentId = $this->createActiveEmployment();
        $programId = $this->createProgram(
            BenefitProgram::BENEFICIARY_SCOPE_EITHER,
        );

        $participation = $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'benefit_program_id' => $programId,
                'effective_from' => '2026-01-01',
            ],
        );

        $this->assertSame(EmployeeBenefitParticipation::STATUS_ELIGIBLE, $participation->status);
        $this->assertNull($participation->beneficiary_person_id);
    }

    public function test_create_succeeds_for_dependent_beneficiary(): void
    {
        $employmentId = $this->createActiveEmployment();
        $programId = $this->createProgram(
            BenefitProgram::BENEFICIARY_SCOPE_EITHER,
        );
        $beneficiaryPersonId = $this->createPerson('Anak Uji');

        $participation = $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'benefit_program_id' => $programId,
                'beneficiary_person_id' => $beneficiaryPersonId,
                'effective_from' => '2026-01-01',
            ],
        );

        $this->assertSame($beneficiaryPersonId, $participation->beneficiary_person_id);
    }

    public function test_create_rejects_employment_not_active(): void
    {
        $employmentId = $this->createPlannedEmployment();
        $programId = $this->createProgram(
            BenefitProgram::BENEFICIARY_SCOPE_EITHER,
        );

        $this->expectException(BenefitParticipationLifecycleException::class);

        $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'benefit_program_id' => $programId,
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_rejects_inactive_program(): void
    {
        $employmentId = $this->createActiveEmployment();
        $program = BenefitProgram::create([
            'code' => 'INACTIVE-' . Str::upper(Str::random(4)),
            'name' => 'Program Nonaktif',
            'category' => BenefitProgram::CATEGORY_STATUTORY,
            'beneficiary_scope' => BenefitProgram::BENEFICIARY_SCOPE_EITHER,
            'payroll_relevance' => BenefitProgram::PAYROLL_RELEVANCE_NONE,
            'is_active' => false,
        ]);

        $this->expectException(BenefitParticipationLifecycleException::class);

        $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'benefit_program_id' => $program->id,
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_rejects_unknown_program(): void
    {
        $employmentId = $this->createActiveEmployment();

        $this->expectException(ModelNotFoundException::class);

        $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'benefit_program_id' => UuidV7::generate(),
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_rejects_dependent_beneficiary_when_program_is_employee_only(): void
    {
        $employmentId = $this->createActiveEmployment();
        $programId = $this->createProgram(
            BenefitProgram::BENEFICIARY_SCOPE_EMPLOYEE,
        );
        $beneficiaryPersonId = $this->createPerson('Anak Uji');

        $this->expectException(BenefitParticipationLifecycleException::class);

        $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'benefit_program_id' => $programId,
                'beneficiary_person_id' => $beneficiaryPersonId,
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_rejects_self_beneficiary_when_program_is_dependent_only(): void
    {
        $employmentId = $this->createActiveEmployment();
        $programId = $this->createProgram(
            BenefitProgram::BENEFICIARY_SCOPE_DEPENDENT,
        );

        $this->expectException(BenefitParticipationLifecycleException::class);

        $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'benefit_program_id' => $programId,
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_rejects_unknown_beneficiary_person(): void
    {
        $employmentId = $this->createActiveEmployment();
        $programId = $this->createProgram(
            BenefitProgram::BENEFICIARY_SCOPE_EITHER,
        );

        $this->expectException(BenefitParticipationLifecycleException::class);

        $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'benefit_program_id' => $programId,
                'beneficiary_person_id' => UuidV7::generate(),
                'effective_from' => '2026-01-01',
            ],
        );
    }

    public function test_create_rejects_duplicate_open_active_participation(): void
    {
        $employmentId = $this->createActiveEmployment();
        $programId = $this->createProgram(
            BenefitProgram::BENEFICIARY_SCOPE_EITHER,
        );

        $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'benefit_program_id' => $programId,
                'effective_from' => '2026-01-01',
            ],
        );

        $this->expectException(BenefitParticipationLifecycleException::class);

        $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'benefit_program_id' => $programId,
                'effective_from' => '2026-02-01',
            ],
        );
    }

    // ---------------------------------------------------------------
    // enroll
    // ---------------------------------------------------------------

    public function test_enroll_transitions_eligible_to_enrolled_and_sets_verification(): void
    {
        $employmentId = $this->createActiveEmployment();
        $programId = $this->createProgram(
            BenefitProgram::BENEFICIARY_SCOPE_EITHER,
        );
        $verifierMembershipId = $this->createMembership();

        $participation = $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'benefit_program_id' => $programId,
                'effective_from' => '2026-01-01',
            ],
        );

        $enrolled = $this->service->enroll(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            participationId: $participation->id,
            verifierMembershipId: $verifierMembershipId,
        );

        $this->assertSame(EmployeeBenefitParticipation::STATUS_ENROLLED, $enrolled->status);
        $this->assertSame($verifierMembershipId, $enrolled->verified_by_membership_id);
        $this->assertNotNull($enrolled->verified_at);
    }

    public function test_enroll_rejects_non_eligible_participation(): void
    {
        $employmentId = $this->createActiveEmployment();
        $programId = $this->createProgram(
            BenefitProgram::BENEFICIARY_SCOPE_EITHER,
        );
        $verifierMembershipId = $this->createMembership();

        $participation = $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'benefit_program_id' => $programId,
                'effective_from' => '2026-01-01',
            ],
        );

        $this->service->enroll(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            participationId: $participation->id,
            verifierMembershipId: $verifierMembershipId,
        );

        $this->expectException(BenefitParticipationLifecycleException::class);

        $this->service->enroll(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            participationId: $participation->id,
            verifierMembershipId: $verifierMembershipId,
        );
    }

    public function test_enroll_rejects_participation_from_different_employment(): void
    {
        $employmentId = $this->createActiveEmployment();
        $otherEmploymentId = $this->createActiveEmployment();
        $programId = $this->createProgram(
            BenefitProgram::BENEFICIARY_SCOPE_EITHER,
        );
        $verifierMembershipId = $this->createMembership();

        $participation = $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'benefit_program_id' => $programId,
                'effective_from' => '2026-01-01',
            ],
        );

        $this->expectException(BenefitParticipationLifecycleException::class);

        $this->service->enroll(
            tenantId: $this->tenantId,
            employmentId: $otherEmploymentId,
            participationId: $participation->id,
            verifierMembershipId: $verifierMembershipId,
        );
    }

    public function test_enroll_rejects_inactive_verifier_membership(): void
    {
        $employmentId = $this->createActiveEmployment();
        $programId = $this->createProgram(
            BenefitProgram::BENEFICIARY_SCOPE_EITHER,
        );
        $inactiveVerifierId = $this->createMembership(status: 'INACTIVE');

        $participation = $this->service->create(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            data: [
                'benefit_program_id' => $programId,
                'effective_from' => '2026-01-01',
            ],
        );

        $this->expectException(BenefitParticipationLifecycleException::class);

        $this->service->enroll(
            tenantId: $this->tenantId,
            employmentId: $employmentId,
            participationId: $participation->id,
            verifierMembershipId: $inactiveVerifierId,
        );
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function activateTenantContext(string $tenantId): void
    {
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
                'benefit-participation-svc-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createMembership(string $status = 'ACTIVE'): string
    {
        $personId = $this->createPerson('Benefit Participation Fixture Person');

        $membershipId = UuidV7::generate();

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $membershipId;
    }

    private function createPerson(string $name): string
    {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => $name,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $personId;
    }

    private function createProgram(string $beneficiaryScope): string
    {
        return BenefitProgram::create([
            'code' => 'PROG-' . Str::upper(Str::random(6)),
            'name' => 'Program Uji',
            'category' => BenefitProgram::CATEGORY_STATUTORY,
            'beneficiary_scope' => $beneficiaryScope,
            'payroll_relevance' => BenefitProgram::PAYROLL_RELEVANCE_ELIGIBILITY_INPUT,
            'is_active' => true,
        ])->id;
    }

    private function createActiveEmployment(): string
    {
        return $this->createEmploymentRow(Employment::STATUS_ACTIVE);
    }

    private function createPlannedEmployment(): string
    {
        return $this->createEmploymentRow(Employment::STATUS_PLANNED);
    }

    private function createEmploymentRow(string $status): string
    {
        $membershipId = $this->createMembership();

        $employeeId = UuidV7::generate();

        DB::table('employees')->insert([
            'id' => $employeeId,
            'tenant_id' => $this->tenantId,
            'membership_id' => $membershipId,
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
            'status' => $status,
            'start_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employmentId;
    }
}
