<?php

declare(strict_types=1);

namespace Modules\Academic\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Academic\Contracts\GuardianRepositoryInterface;
use Modules\Academic\Models\Guardian;
use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Support\Uuid\UuidV7;

final class EloquentGuardianRepository implements GuardianRepositoryInterface
{
    public function getByTenantPaginated(
        string $tenantId,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return $this->baseTenantQuery($tenantId)
            ->orderBy('persons.name')
            ->orderBy('guardians.id')
            ->paginate($perPage);
    }

    public function findByIdForTenant(
        string $id,
        string $tenantId,
    ): array {
        $guardian = $this->baseTenantQuery($tenantId)
            ->where('guardians.id', $id)
            ->first();

        if ($guardian === null) {
            throw (new ModelNotFoundException())->setModel(
                Guardian::class,
                [$id],
            );
        }

        return (array) $guardian;
    }

    public function createProfileForTenant(
        string $tenantId,
        string $membershipId,
    ): array {
        $membershipExists = DB::table('memberships')
            ->where('id', $membershipId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->exists();

        if (! $membershipExists) {
            throw (new ModelNotFoundException())->setModel(
                Membership::class,
                [$membershipId],
            );
        }

        $guardianId = UuidV7::generate();

        DB::table('guardians')->insert([
            'id' => $guardianId,
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->findByIdForTenant(
            $guardianId,
            $tenantId,
        );
    }

    private function baseTenantQuery(string $tenantId): Builder
    {
        $query = DB::table('guardians')
            ->join(
                'memberships',
                'guardians.membership_id',
                '=',
                'memberships.id',
            )
            ->join(
                'persons',
                'memberships.person_id',
                '=',
                'persons.id',
            )
            ->select([
                'guardians.id as guardian_id',
                'guardians.membership_id',
                'memberships.person_id as person_id',
                'guardians.tenant_id',
                'persons.name as nama',
                'memberships.status as membership_status',
                'guardians.created_at',
            ])
            ->where('guardians.tenant_id', $tenantId)
            ->whereNull('guardians.deleted_at');

        $query->selectSub(
            static function (Builder $contactQuery): void {
                $contactQuery
                    ->from('person_contacts')
                    ->select('normalized_value')
                    ->whereColumn(
                        'person_contacts.person_id',
                        'persons.id',
                    )
                    ->where('person_contacts.type', 'phone')
                    ->orderByDesc('person_contacts.is_primary')
                    ->orderBy('person_contacts.created_at')
                    ->orderBy('person_contacts.id')
                    ->limit(1);
            },
            'no_hp',
        );

        return $query;
    }
}
