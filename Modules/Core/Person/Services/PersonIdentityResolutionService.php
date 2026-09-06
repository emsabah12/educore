<?php

declare(strict_types=1);

namespace Modules\Core\Person\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Person\Contracts\PersonIdentifierRepositoryInterface;
use Modules\Core\Person\Contracts\PersonIdentityResolutionServiceInterface;
use Modules\Core\Person\Contracts\PersonRepositoryInterface;
use Modules\Core\Person\Entities\Person;
use Modules\Core\Person\ValueObjects\PersonName;
use Modules\Core\Support\Uuid\UuidV7;

final readonly class PersonIdentityResolutionService implements PersonIdentityResolutionServiceInterface
{
    public function __construct(
        private PersonIdentifierRepositoryInterface $identifierRepository,
        private PersonRepositoryInterface $personRepository,
    ) {}

    public function resolveByStrongIdentifiers(array $strongClaims): array
    {
        /** @var array<string, true> $matchedPersonIds */
        $matchedPersonIds = [];

        foreach ($strongClaims as $claim) {
            $personId = $this->identifierRepository->findPersonIdByFingerprint(
                $claim['type'],
                $claim['issuing_country_code'],
                $claim['value'],
            );

            if ($personId !== null) {
                $matchedPersonIds[$personId] = true;
            }
        }

        $uniqueMatches = array_keys($matchedPersonIds);

        if (count($uniqueMatches) > 1) {
            return [
                'status' => self::STATUS_CONFLICT,
                'person_id' => null,
            ];
        }

        if (count($uniqueMatches) === 1) {
            return [
                'status' => self::STATUS_MATCHED_EXISTING,
                'person_id' => $uniqueMatches[0],
            ];
        }

        return [
            'status' => self::STATUS_UNRESOLVED,
            'person_id' => null,
        ];
    }

    public function createPersonWithIdentifier(
        string $name,
        string $type,
        string $issuingCountryCode,
        string $rawValue,
    ): string {
        return DB::transaction(function () use (
            $name,
            $type,
            $issuingCountryCode,
            $rawValue,
        ): string {
            $person = new Person(
                id: UuidV7::generate(),
                name: new PersonName($name),
            );

            $this->personRepository->save($person);

            // store() sendiri sudah menegakkan constraint unik sebagai
            // penjaga konkurensi terakhir (§9.2 poin 7) — kalau race
            // condition membuat identifier ini "direbut" Person lain di
            // antara resolveByStrongIdentifiers() dan panggilan ini,
            // RuntimeException akan dilempar dan Person baru ini ikut
            // di-rollback (tidak ada Person "yatim" tanpa identifier).
            $this->identifierRepository->store(
                $person->id(),
                $type,
                $issuingCountryCode,
                $rawValue,
            );

            return $person->id();
        });
    }
}
