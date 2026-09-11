<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Person\Contracts\PersonIdentifierCipherInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Contracts\EmployeeBenefitIdentifierRepositoryInterface;
use Modules\HR\Models\BenefitProgram;
use Modules\HR\Models\EmployeeBenefitIdentifier;
use Modules\HR\Models\EmployeeBenefitParticipation;
use Modules\HR\Models\Employment;
use RuntimeException;
use Tests\TestCase;

final class BenefitIdentifierPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeBenefitIdentifierRepositoryInterface $identifierRepository;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identifierRepository = app(EmployeeBenefitIdentifierRepositoryInterface::class);
        $this->tenantId = $this->createTenant('Benefit Identifier Tenant');
        $this->activateTenantContext($this->tenantId);
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_identifier_store_encrypts_value_and_hides_it_from_serialization(): void
    {
        $programId = $this->createProgram('BPJS_KESEHATAN');
        $participationId = $this->createParticipation($programId);

        $stored = $this->identifierRepository->store(
            tenantId: $this->tenantId,
            participationId: $participationId,
            benefitProgramId: $programId,
            identifierType: 'BPJS_KESEHATAN_NUMBER',
            rawValue: '0001234567890',
        );

        $this->assertSame('BPJS_KESEHATAN_NUMBER', $stored['identifier_type']);
        $this->assertSame(EmployeeBenefitIdentifier::STATUS_ACTIVE, $stored['status']);

        $raw = DB::table('employee_benefit_identifiers')
            ->where('id', $stored['id'])
            ->first();

        // Ciphertext tidak pernah mengandung raw value.
        $this->assertStringNotContainsString('0001234567890', (string) $raw->encrypted_value);
        $this->assertSame(64, strlen((string) $raw->value_fingerprint));

        // Cipher yang SAMA (dipakai ulang dari Core) bisa mendekripsi
        // balik ke nilai asli — membuktikan ini bukan hash satu arah.
        $cipher = app(PersonIdentifierCipherInterface::class);
        $this->assertSame(
            '0001234567890',
            $cipher->decrypt((string) $raw->encrypted_value),
        );

        // Model tidak pernah membocorkan ciphertext lewat toArray()/JSON.
        $model = EmployeeBenefitIdentifier::query()->findOrFail($stored['id']);
        $this->assertArrayNotHasKey('encrypted_value', $model->toArray());
    }

    public function test_identifier_store_rejects_duplicate_within_same_tenant(): void
    {
        $programId = $this->createProgram('BPJS_KESEHATAN');
        $firstParticipationId = $this->createParticipation($programId);
        $secondParticipationId = $this->createParticipation($programId);

        $this->identifierRepository->store(
            tenantId: $this->tenantId,
            participationId: $firstParticipationId,
            benefitProgramId: $programId,
            identifierType: 'BPJS_KESEHATAN_NUMBER',
            rawValue: '0009999999999',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already registered');

        $this->identifierRepository->store(
            tenantId: $this->tenantId,
            participationId: $secondParticipationId,
            benefitProgramId: $programId,
            identifierType: 'BPJS_KESEHATAN_NUMBER',
            rawValue: '0009999999999',
        );
    }

    public function test_identifier_allows_same_value_across_different_tenants(): void
    {
        $programId = $this->createProgram('BPJS_KESEHATAN');
        $participationId = $this->createParticipation($programId);

        $this->identifierRepository->store(
            tenantId: $this->tenantId,
            participationId: $participationId,
            benefitProgramId: $programId,
            identifierType: 'BPJS_KESEHATAN_NUMBER',
            rawValue: '0008888888888',
        );

        $otherTenantId = $this->createTenant('Benefit Identifier Other Tenant');
        $this->activateTenantContext($otherTenantId);
        $otherProgramId = $this->createProgram('BPJS_KESEHATAN');
        $otherParticipationId = $this->createParticipation($otherProgramId);

        $storedInOtherTenant = $this->identifierRepository->store(
            tenantId: $otherTenantId,
            participationId: $otherParticipationId,
            benefitProgramId: $otherProgramId,
            identifierType: 'BPJS_KESEHATAN_NUMBER',
            rawValue: '0008888888888',
        );

        $this->assertNotNull($storedInOtherTenant['id']);
    }

    public function test_identifier_allows_same_value_for_different_program(): void
    {
        $healthProgramId = $this->createProgram('BPJS_KESEHATAN');
        $healthParticipationId = $this->createParticipation($healthProgramId);

        $this->identifierRepository->store(
            tenantId: $this->tenantId,
            participationId: $healthParticipationId,
            benefitProgramId: $healthProgramId,
            identifierType: 'MEMBER_NUMBER',
            rawValue: '0007777777777',
        );

        $employmentProgramId = $this->createProgram('BPJS_KETENAGAKERJAAN');
        $employmentParticipationId = $this->createParticipation($employmentProgramId);

        $storedForOtherProgram = $this->identifierRepository->store(
            tenantId: $this->tenantId,
            participationId: $employmentParticipationId,
            benefitProgramId: $employmentProgramId,
            identifierType: 'MEMBER_NUMBER',
            rawValue: '0007777777777',
        );

        $this->assertNotNull($storedForOtherProgram['id']);
    }

    public function test_list_for_participation_with_decrypted_value_returns_active_only(): void
    {
        $programId = $this->createProgram('BPJS_KESEHATAN');
        $participationId = $this->createParticipation($programId);

        $active = $this->identifierRepository->store(
            tenantId: $this->tenantId,
            participationId: $participationId,
            benefitProgramId: $programId,
            identifierType: 'BPJS_KESEHATAN_NUMBER',
            rawValue: '0001111111111',
        );

        $inactive = $this->identifierRepository->store(
            tenantId: $this->tenantId,
            participationId: $participationId,
            benefitProgramId: $programId,
            identifierType: 'OLD_MEMBER_NUMBER',
            rawValue: '0002222222222',
        );

        EmployeeBenefitIdentifier::query()
            ->whereKey($inactive['id'])
            ->update(['status' => EmployeeBenefitIdentifier::STATUS_INACTIVE]);

        $list = $this->identifierRepository->listForParticipationWithDecryptedValue(
            tenantId: $this->tenantId,
            participationId: $participationId,
        );

        $this->assertCount(1, $list);
        $this->assertSame('BPJS_KESEHATAN_NUMBER', $list[0]['identifier_type']);
        $this->assertSame('0001111111111', $list[0]['value']);
    }

    public function test_composite_foreign_key_rejects_mismatched_benefit_program(): void
    {
        $healthProgramId = $this->createProgram('BPJS_KESEHATAN');
        $employmentProgramId = $this->createProgram('BPJS_KETENAGAKERJAAN');
        $healthParticipationId = $this->createParticipation($healthProgramId);

        // participationId benar-benar milik healthProgramId, tapi
        // benefitProgramId yang diberikan sengaja SALAH (program
        // lain) — FK komposit 3-kolom di DB wajib menolak ini.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('do not reference a matching');

        $this->identifierRepository->store(
            tenantId: $this->tenantId,
            participationId: $healthParticipationId,
            benefitProgramId: $employmentProgramId,
            identifierType: 'BPJS_KESEHATAN_NUMBER',
            rawValue: '0003333333333',
        );
    }

    public function test_composite_foreign_key_rejects_unknown_participation(): void
    {
        $programId = $this->createProgram('BPJS_KESEHATAN');

        $this->expectException(RuntimeException::class);

        $this->identifierRepository->store(
            tenantId: $this->tenantId,
            participationId: UuidV7::generate(),
            benefitProgramId: $programId,
            identifierType: 'BPJS_KESEHATAN_NUMBER',
            rawValue: '0004444444444',
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
                'benefit-identifier-%s',
                Str::lower(Str::random(12)),
            ),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createProgram(string $code): string
    {
        return BenefitProgram::create([
            'code' => $code.'-'.Str::upper(Str::random(4)),
            'name' => 'Program Uji '.$code,
            'category' => BenefitProgram::CATEGORY_STATUTORY,
            'beneficiary_scope' => BenefitProgram::BENEFICIARY_SCOPE_EITHER,
            'payroll_relevance' => BenefitProgram::PAYROLL_RELEVANCE_ELIGIBILITY_INPUT,
            'is_active' => true,
        ])->id;
    }

    private function createParticipation(string $programId): string
    {
        $employmentId = $this->createEmployment();

        return EmployeeBenefitParticipation::create([
            'employment_id' => $employmentId,
            'benefit_program_id' => $programId,
            'status' => EmployeeBenefitParticipation::STATUS_ELIGIBLE,
            'effective_from' => '2026-01-01',
        ])->id;
    }

    private function createEmployment(): string
    {
        // Baca tenant yang SEDANG AKTIF, bukan $this->tenantId (yang
        // hanya benar untuk tenant pertama di setUp()) — beberapa
        // test di file ini sengaja pindah tenant lewat
        // activateTenantContext() di tengah jalan (mis. test lintas-
        // tenant), dan insert mentah lewat DB::table() di bawah TIDAK
        // otomatis ikut BelongsToTenant seperti Eloquent create().
        $activeTenantId = app(TenantContextInterface::class)->getCurrentTenantId();

        $membershipId = UuidV7::generate();
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Benefit Identifier Fixture Employee',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('memberships')->insert([
            'id' => $membershipId,
            'person_id' => $personId,
            'tenant_id' => $activeTenantId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = UuidV7::generate();

        DB::table('employees')->insert([
            'id' => $employeeId,
            'tenant_id' => $activeTenantId,
            'membership_id' => $membershipId,
            'nip' => sprintf('NIP-%s', Str::upper(Str::random(8))),
            'jabatan' => 'GURU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employmentId = UuidV7::generate();

        DB::table('employments')->insert([
            'id' => $employmentId,
            'tenant_id' => $activeTenantId,
            'employee_id' => $employeeId,
            'status' => Employment::STATUS_ACTIVE,
            'start_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employmentId;
    }
}
