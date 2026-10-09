<?php

namespace Modules\Core\Application\Organization;

use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat yang membaca/menulis closure table `organization_closure`.
 *
 * Semua query memakai filter tenant_id eksplisit (PRD-000 §5) dan parameter
 * terikat (binding), tidak pernah menyusun SQL dari input pengguna.
 *
 * Istilah:
 *   level  = posisi node dihitung dari atas; node langsung di bawah Yayasan = 1.
 *   tinggi = jarak terjauh dari node ke turunannya; node tanpa anak = 0.
 */
final class OrganizationTree
{
    /** Batas kedalaman pohon (PRD-000 OD-03). */
    public const MAX_LEVEL = 6;

    /**
     * Daftarkan node baru (belum punya anak) ke closure table.
     * Wajib dipanggil di dalam transaksi yang sama dengan insert ke `organizations`.
     */
    public function insertLeaf(string $tenantId, string $nodeId, ?string $parentId): void
    {
        // Baris diri sendiri (depth 0).
        DB::insert(
            'INSERT INTO organization_closure (tenant_id, ancestor_id, descendant_id, depth)
             VALUES (CAST(? AS uuid), CAST(? AS uuid), CAST(? AS uuid), 0)',
            [$tenantId, $nodeId, $nodeId],
        );

        if ($parentId === null) {
            return;
        }

        // Salin semua induk dari parent, lalu tambah jaraknya 1.
        DB::insert(
            'INSERT INTO organization_closure (tenant_id, ancestor_id, descendant_id, depth)
             SELECT tenant_id, ancestor_id, CAST(? AS uuid), depth + 1
             FROM organization_closure
             WHERE tenant_id = CAST(? AS uuid) AND descendant_id = CAST(? AS uuid)',
            [$nodeId, $tenantId, $parentId],
        );
    }

    /**
     * Pindahkan node beserta seluruh turunannya ke induk baru (null = langsung di bawah Yayasan).
     * Pemanggil wajib sudah memastikan tidak terjadi siklus dan tidak melebihi kedalaman.
     */
    public function moveSubtree(string $tenantId, string $nodeId, ?string $newParentId): void
    {
        // 1. Putus hubungan antara induk-induk LAMA (di luar subtree) dan seluruh isi subtree.
        //    Hubungan di dalam subtree sendiri tetap dipertahankan.
        DB::delete(
            'DELETE FROM organization_closure
             WHERE tenant_id = CAST(? AS uuid)
               AND descendant_id IN (
                   SELECT descendant_id FROM organization_closure
                   WHERE tenant_id = CAST(? AS uuid) AND ancestor_id = CAST(? AS uuid)
               )
               AND ancestor_id NOT IN (
                   SELECT descendant_id FROM organization_closure
                   WHERE tenant_id = CAST(? AS uuid) AND ancestor_id = CAST(? AS uuid)
               )',
            [$tenantId, $tenantId, $nodeId, $tenantId, $nodeId],
        );

        if ($newParentId === null) {
            return;
        }

        // 2. Sambungkan setiap induk BARU ke setiap anggota subtree.
        DB::insert(
            'INSERT INTO organization_closure (tenant_id, ancestor_id, descendant_id, depth)
             SELECT super.tenant_id, super.ancestor_id, sub.descendant_id, super.depth + sub.depth + 1
             FROM organization_closure AS super
             CROSS JOIN organization_closure AS sub
             WHERE super.tenant_id = CAST(? AS uuid)
               AND sub.tenant_id = CAST(? AS uuid)
               AND super.descendant_id = CAST(? AS uuid)
               AND sub.ancestor_id = CAST(? AS uuid)',
            [$tenantId, $tenantId, $newParentId, $nodeId],
        );
    }

    /**
     * Level node: jumlah induk termasuk dirinya sendiri.
     */
    public function levelOf(string $tenantId, string $nodeId): int
    {
        return DB::table('organization_closure')
            ->where('tenant_id', $tenantId)
            ->where('descendant_id', $nodeId)
            ->count();
    }

    /**
     * Tinggi subtree: jarak terjauh ke turunan.
     */
    public function subtreeHeight(string $tenantId, string $nodeId): int
    {
        $height = DB::table('organization_closure')
            ->where('tenant_id', $tenantId)
            ->where('ancestor_id', $nodeId)
            ->max('depth');

        return (int) $height;
    }

    public function isDescendantOrSelf(string $tenantId, string $ancestorId, string $nodeId): bool
    {
        return DB::table('organization_closure')
            ->where('tenant_id', $tenantId)
            ->where('ancestor_id', $ancestorId)
            ->where('descendant_id', $nodeId)
            ->exists();
    }

    /**
     * ID node itu sendiri + semua turunannya, dari yang terdekat.
     *
     * @return array<int, string>
     */
    public function descendantIds(string $tenantId, string $nodeId): array
    {
        return DB::table('organization_closure')
            ->where('tenant_id', $tenantId)
            ->where('ancestor_id', $nodeId)
            ->orderBy('depth')
            ->orderBy('descendant_id')
            ->pluck('descendant_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * ID node itu sendiri + semua induknya, dari yang terdekat sampai paling atas.
     *
     * @return array<int, string>
     */
    public function ancestorIds(string $tenantId, string $nodeId): array
    {
        return DB::table('organization_closure')
            ->where('tenant_id', $tenantId)
            ->where('descendant_id', $nodeId)
            ->orderBy('depth')
            ->pluck('ancestor_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
