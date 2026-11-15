<?php

namespace Modules\Dormitory\Application\Placement;

use Illuminate\Support\Facades\DB;
use Modules\Dormitory\Domain\Placement\PlacementCategory;
use Modules\Dormitory\Domain\Placement\PlacementStatus;

/**
 * Tanda "Perlu ditinjau" (PRD-008 §5.6, §19.1; OD-DM-06): santri Aktif/Direncanakan yang sudah tidak punya
 * pendaftaran terbuka di lembaga milik asramanya. Dihitung saat dibaca dari data Academic (lewat kontrak), bukan
 * disimpan, sehingga selalu sesuai keadaan terbaru [OWNER, 2026-11-15]. Tidak ada yang dikeluarkan otomatis.
 */
final class ResidentReview
{
    public function __construct(
        private readonly ResidentEligibility $eligibility,
    ) {}

    /**
     * Dari penempatan santri yang diberikan, mana yang perlu ditinjau.
     *
     * @param  iterable<array{id: string, membership_id: string, organization_id: string}>  $placements
     * @return list<string> id penempatan
     */
    public function flagged(string $tenantId, iterable $placements): array
    {
        $byOwner = [];

        foreach ($placements as $placement) {
            $byOwner[$placement['organization_id']][] = $placement;
        }

        $flagged = [];

        foreach ($byOwner as $ownerId => $rows) {
            $memberships = array_values(array_unique(array_column($rows, 'membership_id')));
            $eligible = array_flip($this->eligibility->eligibleAmong($tenantId, (string) $ownerId, $memberships, PlacementCategory::Resident));

            foreach ($rows as $row) {
                if (! isset($eligible[$row['membership_id']])) {
                    $flagged[] = $row['id'];
                }
            }
        }

        return $flagged;
    }

    /**
     * Semua penempatan santri Aktif/Direncanakan di asrama milik $ownerIds yang perlu ditinjau.
     *
     * @param  list<string>  $ownerIds
     * @return list<string> id penempatan
     */
    public function flaggedIn(string $tenantId, array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        $rows = [];

        foreach (DB::table('dm_placements')
            ->where('tenant_id', $tenantId)
            ->whereIn('organization_id', $ownerIds)
            ->where('category', PlacementCategory::Resident->value)
            ->whereIn('status', PlacementStatus::holdingValues())
            ->get(['id', 'membership_id', 'organization_id']) as $row) {
            $rows[] = ['id' => (string) $row->id, 'membership_id' => (string) $row->membership_id, 'organization_id' => (string) $row->organization_id];
        }

        return $this->flagged($tenantId, $rows);
    }
}
