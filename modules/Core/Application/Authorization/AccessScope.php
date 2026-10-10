<?php

namespace Modules\Core\Application\Authorization;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Organization\Jenjang;

/**
 * Menghitung cakupan node sebuah penugasan memakai closure table (PRD-000 §7.1):
 *
 *   filter kosong → node A + semua turunan A
 *   filter F      → setiap node M di bawah A (termasuk A) yang jenjang-nya F, + semua turunan M
 *   A kosong + F  → setiap node berjenjang F di seluruh yayasan, + semua turunannya
 *
 * Node nonaktif tetap ikut dihitung agar riwayat datanya tetap terlihat oleh atasannya [ASUMSI].
 * Hasil di-cache di objek ini saja (satu request); tidak ada cache lintas request (PRD-000 §7.2).
 */
final class AccessScope
{
    /** @var array<string, array<string, true>> */
    private array $cache = [];

    /**
     * Set ID node (sebagai key array) yang tercakup.
     * Bila $organizationId dan $jenjang sama-sama null: seluruh node yayasan.
     *
     * @return array<string, true>
     */
    public function coverage(string $tenantId, ?string $organizationId, ?Jenjang $jenjang): array
    {
        $cacheKey = $tenantId.'|'.($organizationId ?? '*').'|'.($jenjang->value ?? '*');

        return $this->cache[$cacheKey] ??= $this->toSet($this->query($tenantId, $organizationId, $jenjang));
    }

    /**
     * Seluruh node milik yayasan.
     *
     * @return array<string, true>
     */
    public function allNodes(string $tenantId): array
    {
        return $this->coverage($tenantId, null, null);
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * @return iterable<mixed>
     */
    private function query(string $tenantId, ?string $organizationId, ?Jenjang $jenjang): iterable
    {
        if ($organizationId === null && $jenjang === null) {
            return DB::table('organizations')->where('tenant_id', $tenantId)->pluck('id');
        }

        if ($jenjang === null) {
            return DB::table('organization_closure')
                ->where('tenant_id', $tenantId)
                ->where('ancestor_id', $organizationId)
                ->pluck('descendant_id');
        }

        // Fungsional: cari dulu node M berjenjang F, lalu ambil M + semua turunannya.
        $query = DB::table('organizations as m')
            ->join('organization_closure as below_m', function (JoinClause $join): void {
                $join->on('below_m.tenant_id', '=', 'm.tenant_id')
                    ->on('below_m.ancestor_id', '=', 'm.id');
            })
            ->where('m.tenant_id', $tenantId)
            ->where('m.jenjang', $jenjang->value);

        if ($organizationId !== null) {
            // M wajib berada di bawah A (atau A itu sendiri).
            $query->join('organization_closure as above_m', function (JoinClause $join): void {
                $join->on('above_m.tenant_id', '=', 'm.tenant_id')
                    ->on('above_m.descendant_id', '=', 'm.id');
            })->where('above_m.ancestor_id', $organizationId);
        }

        return $query->distinct()->pluck('below_m.descendant_id');
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return array<string, true>
     */
    private function toSet(iterable $ids): array
    {
        $set = [];

        foreach ($ids as $id) {
            $set[(string) $id] = true;
        }

        return $set;
    }
}
