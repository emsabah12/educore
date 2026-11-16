<?php

namespace Modules\Dormitory\Application\Placement;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Organization\OrganizationTree;
use Modules\Dormitory\Contracts\DormitoryResidents;
use Modules\Dormitory\Domain\Placement\PlacementCategory;
use Modules\Dormitory\Domain\Placement\PlacementStatus;

/** Implementasi kontrak DormitoryResidents dari data penempatan (PRD-003 §20). */
final class PlacementDormitoryResidents implements DormitoryResidents
{
    public function __construct(private readonly OrganizationTree $tree) {}

    public function residentsDuring(string $tenantId, string $organizationId, array $membershipIds, string $from, string $to): array
    {
        $membershipIds = array_values(array_filter($membershipIds, fn (string $id): bool => Str::isUuid($id)));

        if ($membershipIds === [] || ! Str::isUuid($organizationId)) {
            return [];
        }

        // Asrama boleh dihuni santri lembaga ini bila pemiliknya lembaga itu sendiri atau salah satu induknya.
        $owners = array_values($this->tree->ancestorIds($tenantId, $organizationId));

        if ($owners === []) {
            return [];
        }

        $found = [];

        foreach (array_chunk($membershipIds, 500) as $chunk) {
            foreach (DB::table('dm_placements')
                ->where('tenant_id', $tenantId)
                ->whereIn('organization_id', $owners)
                ->whereIn('membership_id', $chunk)
                ->where('category', PlacementCategory::Resident->value)
                ->whereIn('status', [PlacementStatus::Active->value, PlacementStatus::Ended->value])
                ->where('check_in_on', '<=', $to)
                ->where(fn (Builder $query) => $query->whereNull('check_out_on')->orWhere('check_out_on', '>', $from))
                ->distinct()
                ->pluck('membership_id') as $id) {
                $found[(string) $id] = true;
            }
        }

        return array_values(array_filter($membershipIds, fn (string $id): bool => isset($found[$id])));
    }
}
