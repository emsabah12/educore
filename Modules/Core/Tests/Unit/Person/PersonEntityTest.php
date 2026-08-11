<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit\Person;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Core\Person\Entities\Person;
use Modules\Core\Person\Enums\PersonLegalSex;
use Modules\Core\Person\Enums\PersonStatus;
use Modules\Core\Person\ValueObjects\PersonName;
use Modules\Core\Support\Uuid\UuidV7;
use PHPUnit\Framework\TestCase;

final class PersonEntityTest extends TestCase
{
    public function test_it_accepts_canonical_biographical_identity(): void
    {
        $person = new Person(
            id: UuidV7::generate(),
            name: new PersonName('Sukarno'),
            status: PersonStatus::ACTIVE,
            givenName: 'Sukarno',
            birthDate: new DateTimeImmutable('1901-06-06'),
            birthPlaceName: 'Surabaya',
            birthCountryCode: 'id',
            legalSex: PersonLegalSex::MALE,
            civilStatus: 'MARRIED',
        );

        $this->assertSame('Sukarno', (string) $person->name());
        $this->assertSame('Sukarno', $person->givenName());
        $this->assertNull($person->familyName());
        $this->assertSame('ID', $person->birthCountryCode());
        $this->assertSame(PersonLegalSex::MALE, $person->legalSex());
        $this->assertSame('MARRIED', $person->civilStatus());
    }

    public function test_it_rejects_non_uuid_v7_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid UUIDv7');

        new Person(
            id: 'person-1',
            name: new PersonName('Invalid Person'),
        );
    }

    public function test_it_rejects_invalid_country_code_format(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ISO 3166-1 alpha-2');

        new Person(
            id: UuidV7::generate(),
            name: new PersonName('Invalid Country'),
            birthCountryCode: 'Indonesia',
        );
    }
}
