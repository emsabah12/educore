<?php

declare(strict_types=1);

namespace Modules\Academic\Repositories;

use Modules\Academic\Contracts\GuardianRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Support\Uuid\UuidV7;
use Illuminate\Support\Facades\Hash;

final class EloquentGuardianRepository implements guardianRepositoryInterface
{
    /**
     * Menampilkan daftar wali santri berlingkup tenant tertentu lewat kueri JOIN relasional.
     */
    public function getByTenantPaginated(string $tenantId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return DB::table('guardians')
            ->join('memberships', 'guardians.membership_id', '=', 'memberships.id')
            ->join('users', 'memberships.user_id', '=', 'users.id')
            ->select([
                'guardians.id as guardian_id',
                'guardians.tenant_id',
                'users.name as nama',
                'users.email',
                'guardians.no_hp',
                'memberships.status as status_aktif',
                'guardians.created_at'
            ])
            ->where('guardians.tenant_id', '=', $tenantId)
            ->whereNull('guardians.deleted_at')
            ->orderBy('users.name', 'asc')
            ->paginate($perPage);
    }

    /**
     * Mengambil spesifik detail data wali santri terproteksi isolasi tenant.
     */
    public function findByIdForTenant(string $id, string $tenantId): array
    {
        $guardian = DB::table('guardians')
            ->join('memberships', 'guardians.membership_id', '=', 'memberships.id')
            ->join('users', 'memberships.user_id', '=', 'users.id')
            ->select([
                'guardians.id as guardian_id',
                'guardians.tenant_id',
                'guardians.membership_id',
                'users.id as user_id',
                'users.name as nama',
                'users.email',
                'guardians.no_hp',
                'memberships.status as status_aktif',
                'guardians.created_at'
            ])
            ->where('guardians.id', '=', $id)
            ->where('guardians.tenant_id', '=', $tenantId)
            ->whereNull('guardians.deleted_at')
            ->first();

        if (! $guardian) {
            throw new ModelNotFoundException(sprintf('Data wali santri dengan ID %s tidak ditemukan di lembaga ini.', $id));
        }

        return (array) $guardian;
    }

    /**
     * Mendaftarkan profil wali santri secara transaksional penuh lintas 3 tabel.
     */
    public function createForTenant(string $tenantId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $userId = UuidV7::generate();
            $membershipId = UuidV7::generate();
            $guardianId = UuidV7::generate();

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

            // 2. Jembatani keanggotaan institusi ke tabel 'memberships' (Peran: guardian)
            DB::table('memberships')->insert([
                'id' => $membershipId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'role' => 'guardian',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. Masukkan data ke profil ekstensi tabel 'guardians'
            // Catatan: Jika skema tabel awal Anda memiliki variasi nama kolom (misal no_telp), sesuaikan propertinya.
            DB::table('guardians')->insert([
                'id' => $guardianId,
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'no_hp' => $data['no_hp'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->findByIdForTenant($guardianId, $tenantId);
        });
    }
}
