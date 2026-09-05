<?php

declare(strict_types=1);

namespace Modules\HR\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Person\Contracts\PersonIdentifierCipherInterface;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\HR\Contracts\RecruitmentCandidateIdentifierRepositoryInterface;
use Modules\HR\Models\RecruitmentCandidate;
use Modules\HR\Models\RecruitmentCandidateIdentifier;
use RuntimeException;
use Tests\TestCase;

final class RecruitmentCandidatePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private RecruitmentCandidateIdentifierRepositoryInterface $identifierRepository;
    private string $tenantAId;
    private string $tenantBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identifierRepository = app(RecruitmentCandidateIdentifierRepositoryInterface::class);
        $this->tenantAId = $this->createTenant();
        $this->tenantBId = $this->createTenant();
    }

    protected function tearDown(): void
    {
        app(TenantContextInterface::class)->clear();

        parent::tearDown();
    }

    public function test_candidate_can_be_created_with_default_active_status(): void
    {
        $this->activateTenantContext($this->tenantAId);

        $candidate = RecruitmentCandidate::create([
            'display_name' => 'Budi Kandidat',
            'primary_email' => 'budi@example.test',
            'normalized_email' => 'budi@example.test',
        ]);

        $this->assertTrue(Str::isUuid($candidate->id));
        $this->assertSame(RecruitmentCandidate::STATUS_ACTIVE, $candidate->status);
        // INV-REC-001: person_id NULL sampai hiring conversion dijalankan.
        $this->assertNull($candidate->person_id);
    }

    public function test_identifier_store_encrypts_value_and_hides_it_from_serialization(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $candidateId = $this->createCandidate();

        $stored = $this->identifierRepository->store(
            tenantId: $this->tenantAId,
            candidateId: $candidateId,
            type: 'NATIONAL_ID',
            issuingCountryCode: 'id',
            rawValue: '3201234567890001',
        );

        $this->assertSame('ID', $stored['issuing_country_code']);

        $raw = DB::table('recruitment_candidate_identifiers')
            ->where('id', $stored['id'])
            ->first();

        // Ciphertext tidak pernah mengandung raw value.
        $this->assertStringNotContainsString('3201234567890001', (string) $raw->encrypted_value);
        $this->assertSame(64, strlen((string) $raw->value_fingerprint));

        // Cipher yang SAMA (dipakai ulang dari Core) bisa mendekripsi
        // balik ke nilai asli — membuktikan ini bukan hash satu arah.
        $cipher = app(PersonIdentifierCipherInterface::class);
        $this->assertSame(
            '3201234567890001',
            $cipher->decrypt((string) $raw->encrypted_value),
        );

        // Model tidak pernah membocorkan ciphertext lewat toArray()/JSON.
        $model = RecruitmentCandidateIdentifier::query()->findOrFail($stored['id']);
        $this->assertArrayNotHasKey('encrypted_value', $model->toArray());
    }

    public function test_identifier_store_rejects_duplicate_within_same_tenant(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $firstCandidateId = $this->createCandidate();
        $secondCandidateId = $this->createCandidate();

        $this->identifierRepository->store(
            tenantId: $this->tenantAId,
            candidateId: $firstCandidateId,
            type: 'NATIONAL_ID',
            issuingCountryCode: 'ID',
            rawValue: '3201111111111111',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already registered to another Candidate');

        $this->identifierRepository->store(
            tenantId: $this->tenantAId,
            candidateId: $secondCandidateId,
            type: 'NATIONAL_ID',
            issuingCountryCode: 'ID',
            rawValue: '3201111111111111',
        );
    }

    public function test_identifier_allows_same_value_across_different_tenants(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $candidateInTenantA = $this->createCandidate();

        $this->identifierRepository->store(
            tenantId: $this->tenantAId,
            candidateId: $candidateInTenantA,
            type: 'NATIONAL_ID',
            issuingCountryCode: 'ID',
            rawValue: '3202222222222222',
        );

        $this->activateTenantContext($this->tenantBId);
        $candidateInTenantB = $this->createCandidate();

        $storedInTenantB = $this->identifierRepository->store(
            tenantId: $this->tenantBId,
            candidateId: $candidateInTenantB,
            type: 'NATIONAL_ID',
            issuingCountryCode: 'ID',
            rawValue: '3202222222222222',
        );

        $this->assertSame($candidateInTenantB, $storedInTenantB['candidate_id']);
    }

    public function test_find_candidate_id_by_fingerprint_returns_null_when_not_found(): void
    {
        $this->activateTenantContext($this->tenantAId);

        $result = $this->identifierRepository->findCandidateIdByFingerprint(
            tenantId: $this->tenantAId,
            type: 'NATIONAL_ID',
            issuingCountryCode: 'ID',
            rawValue: 'tidak-pernah-didaftarkan',
        );

        $this->assertNull($result);
    }

    public function test_find_candidate_id_by_fingerprint_returns_owner(): void
    {
        $this->activateTenantContext($this->tenantAId);
        $candidateId = $this->createCandidate();

        $this->identifierRepository->store(
            tenantId: $this->tenantAId,
            candidateId: $candidateId,
            type: 'PASSPORT',
            issuingCountryCode: 'ID',
            rawValue: 'X1234567',
        );

        $found = $this->identifierRepository->findCandidateIdByFingerprint(
            tenantId: $this->tenantAId,
            type: 'PASSPORT',
            issuingCountryCode: 'ID',
            rawValue: 'X1234567',
        );

        $this->assertSame($candidateId, $found);
    }

    public function test_composite_foreign_key_rejects_candidate_from_another_tenant(): void
    {
        $this->activateTenantContext($this->tenantBId);
        $candidateFromTenantB = $this->createCandidate();

        $this->expectException(QueryException::class);

        DB::table('recruitment_candidate_identifiers')->insert([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenantAId,
            'candidate_id' => $candidateFromTenantB,
            'type' => 'NATIONAL_ID',
            'issuing_country_code' => 'ID',
            'encrypted_value' => 'irrelevant-ciphertext',
            'value_fingerprint' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
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
            'name' => 'Candidate Tenant',
            'subdomain' => sprintf(
                'candidate-%s',
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
            'display_name' => 'Kandidat Uji ' . Str::random(6),
            'source' => 'MANUAL',
        ])->id;
    }
}
