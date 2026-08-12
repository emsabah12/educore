<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Person\Contracts\PersonRepositoryInterface;
use Modules\Core\Person\Entities\Person;
use Modules\Core\Person\Enums\PersonStatus;
use Modules\Core\Person\ValueObjects\PersonName;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\HR\Contracts\EmployeeRepositoryInterface;

final class EmployeeProvisioningService
{
    public function __construct(
        private readonly PersonRepositoryInterface $personRepository,
        private readonly EmployeeRepositoryInterface $employeeRepository,
    ) {}

    /**
     * @param array{nama:string,nip:string,jabatan:string} $data
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

            return $this->employeeRepository->createProfileForTenant(
                tenantId: $tenantId,
                membershipId: (string) $membership->id,
                data: [
                    'nip' => $data['nip'],
                    'jabatan' => $data['jabatan'],
                ],
            );
        });
    }
}
