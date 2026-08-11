<?php

declare(strict_types=1);

namespace Modules\Academic\Services;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Contracts\Repository\AcademicClassRepositoryInterface;
use Modules\Academic\Contracts\StudentRepositoryInterface;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Person\Contracts\PersonRepositoryInterface;
use Modules\Core\Person\Entities\Person;
use Modules\Core\Person\Enums\PersonStatus;
use Modules\Core\Person\ValueObjects\PersonName;
use Modules\Core\Support\Uuid\UuidV7;

final class StudentProvisioningService
{
    public function __construct(
        private readonly PersonRepositoryInterface $personRepository,
        private readonly AcademicClassRepositoryInterface $classRepository,
        private readonly StudentRepositoryInterface $studentRepository,
    ) {}

    /**
     * @param array{
     *     nama: string,
     *     class_id: string,
     *     nis?: string|null,
     *     nisn?: string|null
     * } $data
     *
     * @return array<string, mixed>
     */
    public function provision(
        string $tenantId,
        array $data,
    ): array {
        // Fail before creating human/tenant participation when the class is not
        // part of the authenticated tenant.
        $this->classRepository->findByIdForTenant(
            $data['class_id'],
            $tenantId,
        );

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

            return $this->studentRepository->createProfileForTenant(
                tenantId: $tenantId,
                membershipId: (string) $membership->id,
                data: [
                    'class_id' => $data['class_id'],
                    'nis' => $data['nis'] ?? null,
                    'nisn' => $data['nisn'] ?? null,
                    'status' => 'active',
                ],
            );
        });
    }
}
