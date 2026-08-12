<?php

declare(strict_types=1);

namespace Modules\Academic\Services;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Contracts\GuardianRepositoryInterface;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Person\Contracts\PersonRepositoryInterface;
use Modules\Core\Person\Entities\Person;
use Modules\Core\Person\Enums\PersonStatus;
use Modules\Core\Person\ValueObjects\PersonName;
use Modules\Core\Support\Uuid\UuidV7;

final class GuardianProvisioningService
{
    public function __construct(
        private readonly PersonRepositoryInterface $personRepository,
        private readonly GuardianRepositoryInterface $guardianRepository,
    ) {}

    /**
     * @param array{nama:string,no_hp?:string|null} $data
     * @return array<string, mixed>
     */
    public function provision(
        string $tenantId,
        array $data,
    ): array {
        return DB::transaction(function () use ($tenantId, $data): array {
            $person = $this->personRepository->save(
                new Person(
                    id: UuidV7::generate(),
                    name: new PersonName($data['nama']),
                    status: PersonStatus::ACTIVE,
                ),
            );

            $membership = Membership::query()->create([
                'person_id' => $person->id(),
                'tenant_id' => $tenantId,
                'status' => 'ACTIVE',
            ]);

            $phone = $this->normalizePhone(
                $data['no_hp'] ?? null,
            );

            if ($phone !== null) {
                DB::table('person_contacts')->insert([
                    'id' => UuidV7::generate(),
                    'person_id' => $person->id(),
                    'type' => 'phone',
                    'value' => $phone,
                    'normalized_value' => $phone,
                    'label' => null,
                    'is_primary' => true,
                    'verified_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $this->guardianRepository->createProfileForTenant(
                tenantId: $tenantId,
                membershipId: (string) $membership->id,
            );
        });
    }

    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $phone = trim($phone);

        if ($phone === '') {
            return null;
        }

        $normalized = preg_replace(
            '/[\\s-]+/',
            '',
            $phone,
        );

        return is_string($normalized) && $normalized !== ''
            ? $normalized
            : null;
    }
}
