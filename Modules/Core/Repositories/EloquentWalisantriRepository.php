<?php

declare(strict_types=1);

namespace Modules\Core\Repositories;

use Modules\Core\Contracts\Repository\WalisantriRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Support\Uuid\UuidV7;
use Illuminate\Support\Facades\Hash;

final class EloquentWalisantriRepository implements WalisantriRepositoryInterface
{
    /**
     * Menampilkan daftar wali santri berlingkup tenant tertentu lewat kueri JOIN relasional.
     */
    public function getByTenantPaginated(string $tenantId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return DB::table('walisantris')
            ->join('memberships', 'walisantris.membership_id', '=', 'memberships.id')
            ->join('users', 'memberships.user_id', '=', 'users.id')
            ->select([
                'walisantris.id as walisantri_id',
                'walisantris.tenant_id',
                'users.name as nama',
                'users.email',
                'walisantris.no_hp',
                'memberships.status as status_aktif',
                'walisantris.created_at'
            ])
            ->where('walisantris.tenant_id', '=', $tenantId)
            ->whereNull('walisantris.deleted_at')
            ->orderBy('users.name', 'asc')
            ->paginate($perPage);
    }

    /**
     * Mengambil spesifik detail data wali santri terproteksi isolasi tenant.
     */
    public function findByIdForTenant(string $id, string $tenantId): array
    {
        $walisantri = DB::table('walisantris')
            ->join('memberships', 'walisantris.membership_id', '=', 'memberships.id')
            ->join('users', 'memberships.user_id', '=', 'users.id')
            ->select([
                'walisantris.id as walisantri_id',
                'walisantris.tenant_id',
                'walisantris.membership_id',
                'users.id as user_id',
                'users.name as nama',
                'users.email',
                'walisantris.no_hp',
                'memberships.status as status_aktif',
                'walisantris.created_at'
            ])
            ->where('walisantris.id', '=', $id)
            ->where('walisantris.tenant_id', '=', $tenantId)
            ->whereNull('walisantris.deleted_at')
            ->first();

        if (! $walisantri) {
            throw new ModelNotFoundException(sprintf('Data wali santri dengan ID %s tidak ditemukan di lembaga ini.', $id));
        }

        return (array) $walisantri;
    }

    /**
     * Mendaftarkan profil wali santri secara transaksional penuh lintas 3 tabel.
     */
    public function createForTenant(string $tenantId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $userId = UuidV7::generate();
            $membershipId = UuidV7::generate();
            $walisantriId = UuidV7::generate();

            // 1. Tanam data identitas ke tabel 'users'
            DB::table('users')->insert([
                'id' => $userId,
                'name' => $data['nama'],
                'email' => $data['email'] ?? 'wali.' . strtolower(UuidV7::generate()) . '@educore.id',
                'password' => Hash::make('P@sswordWali2026'),
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2. Jembatani keanggotaan institusi ke tabel 'memberships' (Peran: WALISANTRI)
            DB::table('memberships')->insert([
                'id' => $membershipId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'role' => 'WALISANTRI',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. Masukkan data ke profil ekstensi tabel 'walisantris'
            // Catatan: Jika skema tabel awal Anda memiliki variasi nama kolom (misal no_telp), sesuaikan propertinya.
            DB::table('walisantris')->insert([
                'id' => $walisantriId,
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'no_hp' => $data['no_hp'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->findByIdForTenant($walisantriId, $tenantId);
        });
    }
}
