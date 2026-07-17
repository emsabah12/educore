<?php

declare(strict_types=1);

namespace Modules\Core\Repositories;

use Modules\Core\Contracts\Repository\WalisantriSantriRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Support\Uuid\UuidV7;
use InvalidArgumentException;

final class EloquentWalisantriSantriRepository implements WalisantriSantriRepositoryInterface
{
    /**
     * Menautkan anak ke wali dengan pengawasan ketat Cross-Tenant Validation.
     */
    public function attachSantri(string $tenantId, string $walisantriId, string $santriId, string $hubungan = 'AYAH'): bool
    {
        return DB::transaction(function () use ($tenantId, $walisantriId, $santriId, $hubungan) {

            // 1. Validasi Keberadaan Wali Santri di Tenant yang Sama
            $waliExists = DB::table('walisantris')
                ->where('id', '=', $walisantriId)
                ->where('tenant_id', '=', $tenantId)
                ->whereNull('deleted_at')
                ->exists();

            // 2. Validasi Keberadaan Santri di Tenant yang Sama
            $santriExists = DB::table('santris')
                ->where('id', '=', $santriId)
                ->where('tenant_id', '=', $tenantId)
                ->whereNull('deleted_at')
                ->exists();

            if (! $waliExists || ! $santriExists) {
                throw new ModelNotFoundException('Data Wali Santri atau Santri tidak valid, atau berada di luar lembaga Anda.');
            }

            // 3. Cek Idempotensi (Apakah sudah terhubung sebelumnya?)
            $alreadyAttached = DB::table('walisantri_santri')
                ->where('tenant_id', '=', $tenantId)
                ->where('walisantri_id', '=', $walisantriId)
                ->where('santri_id', '=', $santriId)
                ->exists();

            if ($alreadyAttached) {
                return true; // Kembalikan true tanpa memasukkan data ganda (Idempotent)
            }

            // 4. Eksekusi Insertion Pivot Data
            return DB::table('walisantri_santri')->insert([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId,
                'walisantri_id' => $walisantriId,
                'santri_id' => $santriId,
                'hubungan' => strtoupper($hubungan),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        });
    }

    /**
     * Memutuskan hubungan perwalian secara aman berlingkup tenant context.
     */
    public function detachSantri(string $tenantId, string $walisantriId, string $santriId): bool
    {
        $affected = DB::table('walisantri_santri')
            ->where('tenant_id', '=', $tenantId)
            ->where('walisantri_id', '=', $walisantriId)
            ->where('santri_id', '=', $santriId)
            ->delete();

        return $affected > 0;
    }

    /**
     * Mengambil daftar anak yang terhubung ke satu wali santri menggunakan teknik JOIN Eager-style.
     */
    public function getSantriByWalisantri(string $tenantId, string $walisantriId): array
    {
        return DB::table('walisantri_santri')
            ->join('santris', 'walisantri_santri.santri_id', '=', 'santris.id')
            ->join('memberships', 'santris.membership_id', '=', 'memberships.id')
            ->join('users', 'memberships.user_id', '=', 'users.id')
            ->join('academic_classes', 'santris.class_id', '=', 'academic_classes.id')
            ->select([
                'santris.id as santri_id',
                'santris.nis',
                'users.name as nama_santri',
                'academic_classes.name as nama_kelas',
                'walisantri_santri.hubungan',
                'walisantri_santri.created_at'
            ])
            ->where('walisantri_santri.tenant_id', '=', $tenantId)
            ->where('walisantri_santri.walisantri_id', '=', $walisantriId)
            ->whereNull('santris.deleted_at')
            ->orderBy('users.name', 'asc')
            ->get()
            ->toArray();
    }
}
