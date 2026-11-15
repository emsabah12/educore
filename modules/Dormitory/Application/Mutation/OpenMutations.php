<?php

namespace Modules\Dormitory\Application\Mutation;

use Illuminate\Support\Facades\DB;
use Modules\Dormitory\Domain\Mutation\MutationStatus;

/**
 * Pengajuan mutasi yang gugur otomatis (PRD-008 §18.2 butir 7): bila penempatan asal berakhir (check-out atau mutasi
 * darurat), pengajuan yang masih Diajukan menjadi Dibatalkan dengan keterangan sistem. Dipanggil di dalam transaksi
 * yang mengakhiri penempatan.
 */
final class OpenMutations
{
    public static function lapse(string $tenantId, string $placementId, string $note, ?string $exceptMutationId = null): int
    {
        return DB::table('dm_mutations')
            ->where('tenant_id', $tenantId)
            ->where('placement_id', $placementId)
            ->where('status', MutationStatus::Requested->value)
            ->when($exceptMutationId !== null, fn ($query) => $query->where('id', '<>', $exceptMutationId))
            ->update([
                'status' => MutationStatus::Cancelled->value,
                'decided_at' => now(),
                'decision_note' => $note,
                'updated_at' => now(),
            ]);
    }
}
