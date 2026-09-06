<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Person\Contracts\PersonIdentifierRepositoryInterface;
use Modules\Core\Person\Contracts\PersonIdentityResolutionServiceInterface;
use Modules\Core\Support\Uuid\UuidV7;
use RuntimeException;
use Tests\TestCase;

final class PersonIdentityResolutionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PersonIdentityResolutionServiceInterface $service;
    private PersonIdentifierRepositoryInterface $identifierRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PersonIdentityResolutionServiceInterface::class);
        $this->identifierRepository = app(PersonIdentifierRepositoryInterface::class);
    }

    public function test_resolve_returns_unresolved_when_no_match(): void
    {
        $result = $this->service->resolveByStrongIdentifiers([
            ['type' => 'NATIONAL_ID', 'issuing_country_code' => 'ID', 'value' => 'TIDAK-PERNAH-ADA'],
        ]);

        $this->assertSame(PersonIdentityResolutionServiceInterface::STATUS_UNRESOLVED, $result['status']);
        $this->assertNull($result['person_id']);
    }

    public function test_resolve_returns_matched_existing_when_single_match(): void
    {
        $personId = $this->createPersonWithIdentifier('NATIONAL_ID', 'ID', '3201111111111111');

        $result = $this->service->resolveByStrongIdentifiers([
            ['type' => 'NATIONAL_ID', 'issuing_country_code' => 'ID', 'value' => '3201111111111111'],
        ]);

        $this->assertSame(PersonIdentityResolutionServiceInterface::STATUS_MATCHED_EXISTING, $result['status']);
        $this->assertSame($personId, $result['person_id']);
    }

    public function test_resolve_returns_conflict_when_claims_match_different_persons(): void
    {
        $this->createPersonWithIdentifier('NATIONAL_ID', 'ID', '3202222222222222');
        $this->createPersonWithIdentifier('PASSPORT', 'ID', 'X9999999');

        $result = $this->service->resolveByStrongIdentifiers([
            ['type' => 'NATIONAL_ID', 'issuing_country_code' => 'ID', 'value' => '3202222222222222'],
            ['type' => 'PASSPORT', 'issuing_country_code' => 'ID', 'value' => 'X9999999'],
        ]);

        $this->assertSame(PersonIdentityResolutionServiceInterface::STATUS_CONFLICT, $result['status']);
        $this->assertNull($result['person_id']);
    }

    public function test_create_person_with_identifier_creates_person_and_identifier(): void
    {
        $personId = $this->service->createPersonWithIdentifier(
            name: 'Budi Kandidat Terpilih',
            type: 'NATIONAL_ID',
            issuingCountryCode: 'ID',
            rawValue: '3203333333333333',
        );

        $this->assertTrue(Str::isUuid($personId));
        $this->assertDatabaseHas('persons', ['id' => $personId, 'name' => 'Budi Kandidat Terpilih']);
        $this->assertTrue(
            $this->identifierRepository->existsByFingerprint('NATIONAL_ID', 'ID', '3203333333333333'),
        );
    }

    public function test_create_person_with_identifier_rejects_and_rolls_back_on_duplicate(): void
    {
        $this->createPersonWithIdentifier('NATIONAL_ID', 'ID', '3204444444444444');

        $countBefore = DB::table('persons')->count();

        $this->expectException(RuntimeException::class);

        try {
            $this->service->createPersonWithIdentifier(
                name: 'Kandidat Duplikat',
                type: 'NATIONAL_ID',
                issuingCountryCode: 'ID',
                rawValue: '3204444444444444',
            );
        } finally {
            // Person baru TIDAK boleh tetap ada walau identifier-nya gagal
            // disimpan (rollback penuh, bukan Person "yatim").
            $this->assertSame($countBefore, DB::table('persons')->count());
        }
    }

    private function createPersonWithIdentifier(
        string $type,
        string $issuingCountryCode,
        string $rawValue,
    ): string {
        $personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $personId,
            'name' => 'Fixture Person ' . Str::random(6),
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->identifierRepository->store($personId, $type, $issuingCountryCode, $rawValue);

        return $personId;
    }
}
