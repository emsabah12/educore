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
use Modules\HR\Models\BenefitProgram;
use Modules\HR\Models\Employment;
use Modules\HR\Models\EmployeeBenefitParticipation;
use Tests\TestCase;

final class BenefitPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->createTenant('Benefit Persistence Tenant');
        $this->activateTenantContext($this->tenantId);
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // BenefitProgram
    // ---------------------------------------------------------------

    public function test_program_can_be_created(): void
    {
        $program = $this->createProgram('BPJS_KESEHATAN');

        $this->assertSame('BPJS_KESEHATAN', $program->code);
        $this->assertTrue($program->is_active);
    }

    public function test_database_rejects_invalid_category(): void
    {
        $this->expectException(QueryException::class);

        BenefitProgram::create([
            'code' => 'INVALID_CATEGORY',
            'name' => 'Program Invalid',
            'category' => 'NOT_A_REAL_CATEGORY',
            'beneficiary_scope' => BenefitProgram::BENEFICIARY_SCOPE_EMPLOYEE,
            'payroll_relevance' => BenefitProgram::PAYROLL_RELEVANCE_NONE,
        ]);
    }

    public function test_database_rejects_invalid_beneficiary_scope(): void
    {
        $this->expectException(QueryException::class);

        BenefitProgram::create([
            'code' => 'INVALID_SCOPE',
            'name' => 'Program Invalid',
            'category' => BenefitProgram::CATEGORY_STATUTORY,
            'beneficiary_scope' => 'NOT_A_REAL_SCOPE',
            'payroll_relevance' => BenefitProgram::PAYROLL_RELEVANCE_NONE,
        ]);
    }

    public function test_database_rejects_invalid_payroll_relevance(): void
    {
        $this->expectException(QueryException::class);

        BenefitProgram::create([
            'code' => 'INVALID_RELEVANCE',
            'name' => 'Program Invalid',
            'category' => BenefitProgram::CATEGORY_STATUTORY,
            'beneficiary_scope' => BenefitProgram::BENEFICIARY_SCOPE_EMPLOYEE,
            'payroll_relevance' => 'NOT_A_REAL_RELEVANCE',
        ]);
    }

    public function test_database_rejects_duplicate_program_code_within_same_tenant(): void
    {
        $this->createProgram('BPJS_KESEHATAN');

        $this->expectException(QueryException::class);

        $this->createProgram('BPJS_KESEHATAN');
    }

    public function test_same_program_code_is_allowed_across_different_tenants(): void
    {
        $this->createProgram('BPJS_KESEHATAN');

        $otherTenantId = $this->createTenant('Benefit Other Tenant');
        $this->activateTenantContext($otherTenantId);

        $program = $this->createProgram('BPJS_KESEHATAN');

        $this->assertSame($otherTenantId, $program->tenant_id);
    }

    // ---------------------------------------------------------------
    // EmployeeBenefitParticipation
    // ---------------------------------------------------------------

    public function test_participation_can_be_created_for_self_beneficiary(): void
    {
        $employmentId = $this->createEmployment();
        $programId = $this->createProgram('BPJS_KESEHATAN')->id;

        $participation = EmployeeBenefitParticipation::create([
            'employment_id' => $employmentId,
            'benefit_program_id' => $programId,
            'beneficiary_person_id' => null,
            'status' => EmployeeBenefitParticipation::STATUS_ELIGIBLE,
            'effective_from' => '2026-01-01',
        ]);

        $this->assertNull($participation->beneficiary_person_id);
        $this->assertSame(EmployeeBenefitParticipation::STATUS_ELIGIBLE, $participation->status);
    }

    public function test_participation_can_be_created_for_dependent_beneficiary(): void
    {
        $employmentId = $this->createEmployment();
        $programId = $this->createProgram('EMPLOYEE_CHILD_EDUCATION')->id;
        $beneficiaryPersonId = $this->createPerson('Anak Uji');

        $participation = EmployeeBenefitParticipation::create([
            'employment_id' => $employmentId,
            'benefit_program_id' => $programId,
            'beneficiary_person_id' => $beneficiaryPersonId,
            'status' => EmployeeBenefitParticipation::STATUS_ELIGIBLE,
            'effective_from' => '2026-01-01',
        ]);

        $this->assertSame($beneficiaryPersonId, $participation->beneficiary_person_id);
    }

    public function test_database_rejects_invalid_status(): void
    {
        $employmentId = $this->createEmployment();
        $programId = $this->createProgram('BPJS_KESEHATAN')->id;

        $this->expectException(QueryException::class);

        EmployeeBenefitParticipation::create([
            'employment_id' => $employmentId,
            'benefit_program_id' => $programId,
            'status' => 'NOT_A_REAL_STATUS',
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_database_rejects_effective_to_before_effective_from(): void
    {
        $employmentId = $this->createEmployment();
        $programId = $this->createProgram('BPJS_KESEHATAN')->id;

        $this->expectException(QueryException::class);

        EmployeeBenefitParticipation::create([
            'employment_id' => $employmentId,
            'benefit_program_id' => $programId,
            'status' => EmployeeBenefitParticipation::STATUS_ELIGIBLE,
            'effective_from' => '2026-06-01',
            'effective_to' => '2026-01-01',
        ]);
    }

    public function test_database_rejects_duplicate_open_active_participation_for_self(): void
    {
        $employmentId = $this->createEmployment();
        $programId = $this->createProgram('BPJS_KESEHATAN')->id;

        EmployeeBenefitParticipation::create([
            'employment_id' => $employmentId,
            'benefit_program_id' => $programId,
            'status' => EmployeeBenefitParticipation::STATUS_ELIGIBLE,
            'effective_from' => '2026-01-01',
        ]);

        $this->expectException(QueryException::class);

        EmployeeBenefitParticipation::create([
            'employment_id' => $employmentId,
            'benefit_program_id' => $programId,
            'status' => EmployeeBenefitParticipation::STATUS_ENROLLED,
            'effective_from' => '2026-02-01',
        ]);
    }

    public function test_database_rejects_duplicate_open_active_participation_for_same_dependent(): void
    {
        $employmentId = $this->createEmployment();
        $programId = $this->createProgram('EMPLOYEE_CHILD_EDUCATION')->id;
        $beneficiaryPersonId = $this->createPerson('Anak Uji');

        EmployeeBenefitParticipation::create([
            'employment_id' => $employmentId,
            'benefit_program_id' => $programId,
            'beneficiary_person_id' => $beneficiaryPersonId,
            'status' => EmployeeBenefitParticipation::STATUS_ELIGIBLE,
            'effective_from' => '2026-01-01',
        ]);

        $this->expectException(QueryException::class);

        EmployeeBenefitParticipation::create([
            'employment_id' => $employmentId,
            'benefit_program_id' => $programId,
            'beneficiary_person_id' => $beneficiaryPersonId,
            'status' => EmployeeBenefitParticipation::STATUS_ENROLLED,
            'effective_from' => '2026-02-01',
        ]);
    }

    public function test_database_allows_new_open_participation_after_previous_is_closed(): void
    {
        $employmentId = $this->createEmployment();
        $programId = $this->createProgram('BPJS_KESEHATAN')->id;

        $first = EmployeeBenefitParticipation::create([
            'employment_id' => $employmentId,
            'benefit_program_id' => $programId,
            'status' => EmployeeBenefitParticipation::STATUS_ELIGIBLE,
            'effective_from' => '2026-01-01',
        ]);

        $first->update([
            'status' => EmployeeBenefitParticipation::STATUS_ENDED,
            'effective_to' => '2026-01-31',
        ]);

        $second = EmployeeBenefitParticipation::create([
            'employment_id' => $employmentId,
            'benefit_program_id' => $programId,
            'status' => EmployeeBenefitParticipation::STATUS_ELIGIBLE,
            'effective_from' => '2026-02-01',
        ]);

        $this->assertNotNull($second->id);
    }

    public function test_database_allows_open_participation_alongside_suspended_open_row(): void
    {
        $employmentId = $this->createEmployment();
        $programId = $this->createProgram('BPJS_KESEHATAN')->id;

        EmployeeBenefitParticipation::create([
            'employment_id' => $employmentId,
            'benefit_program_id' => $programId,
            'status' => EmployeeBenefitParticipation::STATUS_SUSPENDED,
            'effective_from' => '2026-01-01',
        ]);

        $second = EmployeeBenefitParticipation::create([
            'employment_id' => $employmentId,
            'benefit_program_id' => $programId,
            'status' => EmployeeBenefitParticipation::STATUS_ELIGIBLE,
            'effective_from' => '2026-01-01',
        ]);

        $this->assertNotNull($second->id);
    }

    public function test_composite_foreign_key_rejects_program_from_another_tenant(): void
    {
        $otherTenantId = $this->createTenant('Benefit Foreign Tenant');
        $this->activateTenantContext($otherTenantId);
        $foreignProgramId = $this->createProgram('BPJS_KESEHATAN')->id;

        $this->activateTenantContext($this->tenantId);
        $employmentId = $this->createEmployment();

        $this->expectException(QueryException::class);

        DB::table('employee_benefit_participations')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantId,
            'employment_id' => $employmentId,
            'benefit_program_id' => $foreignProgramId,
            'status' => EmployeeBenefitParticipation::STATUS_ELIGIBLE,
            'effective_from' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_hard_delete_of_program_referenced_by_participation_is_restricted(): void
    {
        $program = $this->createProgram('BPJS_KESEHATAN');
        $employmentId = $this->createEmployment();

        EmployeeBenefitParticipation::create([
            'employment_id' => $employmentId,
            'benefit_program_id' => $program->id,
            'status' => EmployeeBenefitParticipation::STATUS_ELIGIBLE,
            'effective_from' => '2026-01-01',
        ]);

        $this->expectException(QueryException::class);

        DB::table('benefit_programs')
            ->where('id', $program->id)
            ->delete();
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
                'benefit-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createProgram(string $code): BenefitProgram
    {
        return BenefitProgram::create([
            'code' => $code,
            'name' => 'Program Uji ' . $code,
            'category' => BenefitProgram::CATEGORY_STATUTORY,
            'beneficiary_scope' => BenefitProgram::BENEFICIARY_SCOPE_EITHER,
            'payroll_relevance' => BenefitProgram::PAYROLL_RELEVANCE_ELIGIBILITY_INPUT,
            'is_active' => true,
        ]);
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

    private function createEmployment(): string
    {
        $membershipId = UuidV7::generate();
        $personId = $this->createPerson('Benefit Fixture Employee');

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $this->tenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
            'status' => Employment::STATUS_ACTIVE,
            'start_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employmentId;
    }
}
