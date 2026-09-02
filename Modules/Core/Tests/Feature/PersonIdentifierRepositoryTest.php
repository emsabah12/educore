<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Person\Contracts\PersonIdentifierRepositoryInterface;
use Modules\Core\Support\Uuid\UuidV7;
use RuntimeException;
use Tests\TestCase;

final class PersonIdentifierRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private string $personId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->personId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $this->personId,
            'name' => 'Canonical Identifier Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_store_never_persists_raw_value_in_encrypted_value_column(): void
    {
        $rawNik = '3201234567890001';

        app(PersonIdentifierRepositoryInterface::class)->store(
            $this->personId,
            'NATIONAL_ID',
            'ID',
            $rawNik,
        );

        $record = DB::table('person_identifiers')
            ->where('person_id', $this->personId)
            ->first();

        $this->assertNotNull($record);

        $this->assertStringNotContainsString(
            $rawNik,
            (string) $record->encrypted_value,
        );
        $this->assertStringNotContainsString(
            $rawNik,
            (string) $record->value_fingerprint,
        );
    }

    public function test_store_persists_sixty_four_character_hex_fingerprint(): void
    {
        app(PersonIdentifierRepositoryInterface::class)->store(
            $this->personId,
            'NATIONAL_ID',
            'ID',
            '3201234567890001',
        );

        $record = DB::table('person_identifiers')
            ->where('person_id', $this->personId)
            ->first();

        $this->assertSame(64, strlen((string) $record->value_fingerprint));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            (string) $record->value_fingerprint,
        );
    }

    public function test_list_for_person_returns_correctly_decrypted_value(): void
    {
        $rawNik = '3201234567890001';

        app(PersonIdentifierRepositoryInterface::class)->store(
            $this->personId,
            'NATIONAL_ID',
            'ID',
            $rawNik,
            'Dukcapil',
        );

        $identifiers = app(PersonIdentifierRepositoryInterface::class)
            ->listForPersonWithDecryptedValue($this->personId);

        $this->assertCount(1, $identifiers);
        $this->assertSame($rawNik, $identifiers[0]['value']);
        $this->assertSame('NATIONAL_ID', $identifiers[0]['type']);
        $this->assertSame('ID', $identifiers[0]['issuing_country_code']);
        $this->assertSame('Dukcapil', $identifiers[0]['issuer']);
    }

    public function test_store_rejects_duplicate_identifier_across_persons(): void
    {
        $rawNik = '3201234567890001';

        app(PersonIdentifierRepositoryInterface::class)->store(
            $this->personId,
            'NATIONAL_ID',
            'ID',
            $rawNik,
        );

        $otherPersonId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $otherPersonId,
            'name' => 'Other Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'already registered to another Person',
        );

        app(PersonIdentifierRepositoryInterface::class)->store(
            $otherPersonId,
            'NATIONAL_ID',
            'ID',
            $rawNik,
        );
    }

    public function test_existence_by_fingerprint_detects_duplicate_without_exposing_raw_value(): void
    {
        $rawNik = '3201234567890001';

        app(PersonIdentifierRepositoryInterface::class)->store(
            $this->personId,
            'NATIONAL_ID',
            'ID',
            $rawNik,
        );

        $repository = app(PersonIdentifierRepositoryInterface::class);

        $this->assertTrue(
            $repository->existsByFingerprint('NATIONAL_ID', 'ID', $rawNik),
        );
        $this->assertFalse(
            $repository->existsByFingerprint('NATIONAL_ID', 'ID', '3201234567890099'),
        );
    }

    public function test_same_value_is_allowed_for_different_identifier_type(): void
    {
        // Type berbeda (mis. NATIONAL_ID vs PASSPORT) dengan angka
        // kebetulan sama secara string tidak boleh dianggap duplikat —
        // unique constraint eksplisit mencakup (type, country, fingerprint).
        $sameStringValue = '3201234567890001';

        app(PersonIdentifierRepositoryInterface::class)->store(
            $this->personId,
            'NATIONAL_ID',
            'ID',
            $sameStringValue,
        );

        $otherPersonId = UuidV7::generate();

        DB::table('persons')->insert([
            'id' => $otherPersonId,
            'name' => 'Other Person',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(PersonIdentifierRepositoryInterface::class)->store(
            $otherPersonId,
            'PASSPORT',
            'ID',
            $sameStringValue,
        );

        $this->assertSame('PASSPORT', $result['type']);
    }
}
