<?php

declare(strict_types=1);

namespace Modules\Core\Repositories;

use Modules\Core\Contracts\Repository\PegawaiRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Support\Uuid\UuidV7;
use Illuminate\Support\Facades\Hash;

final class EloquentPegawaiRepository implements PegawaiRepositoryInterface
{
    /**
     * Mengambil daftar pegawai terisolasi berdasarkan scope tenant_id dengan teknik JOIN Relasional.
     */
    public function getByTenantPaginated(string $tenantId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return DB::table('pegawais')
            ->join('memberships', 'pegawais.membership_id', '=', 'memberships.id')
            ->join('users', 'memberships.user_id', '=', 'users.id')
            ->select([
                'pegawais.id as pegawai_id',
                'pegawais.tenant_id',
                'pegawais.nip',
                'pegawais.jabatan',
                'users.name as nama',
                'users.email',
                'memberships.status as status_aktif',
                'pegawais.created_at'
            ])
            ->where('pegawais.tenant_id', '=', $tenantId)
            ->orderBy('pegawais.created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Mendapatkan spesifik detail pegawai dengan kawalan ketat cross-tenant block check via JOIN.
     */
    public function findByIdForTenant(string $id, string $tenantId): array
    {
        $pegawai = DB::table('pegawais')
            ->join('memberships', 'pegawais.membership_id', '=', 'memberships.id')
            ->join('users', 'memberships.user_id', '=', 'users.id')
            ->select([
                'pegawais.id as pegawai_id',
                'pegawais.tenant_id',
                'pegawais.membership_id',
                'pegawais.nip',
                'pegawais.jabatan',
                'users.id as user_id',
                'users.name as nama',
                'users.email',
                'memberships.status as status_aktif',
                'pegawais.created_at'
            ])
            ->where('pegawais.id', '=', $id)
            ->where('pegawais.tenant_id', '=', $tenantId)
            ->first();

        if (! $pegawai) {
            throw new ModelNotFoundException(
                sprintf('Data staf pegawai dengan ID %s tidak ditemukan pada lembaga ini.', $id)
            );
        }

        return (array) $pegawai;
    }

    /**
     * Menyimpan data pegawai secara atomik lintas 3 tabel (users -> memberships -> pegawais).
     */
    public function createForTenant(string $tenantId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $userId = UuidV7::generate();
            $membershipId = UuidV7::generate();
            $pegawaiId = UuidV7::generate();

            // 1. Amankan data di tabel 'users' terlebih dahulu
            // Menggunakan password default aman terenkripsi yang wajib diubah saat login pertama
            DB::table('users')->insert([
                'id' => $userId,
                'name' => $data['nama'],
                'email' => $data['email'] ?? strtolower(str_replace(' ', '', $data['nama'])) . '@educore.id',
                'password' => Hash::make('P@sswordPegawai2026'),
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2. Jembatani keanggotaan di tabel 'memberships'
            DB::table('memberships')->insert([
                'id' => $membershipId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'role' => 'PEGAWAI',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. Masukkan data spesifik profesi ke tabel utama 'pegawais'
            DB::table('pegawais')->insert([
                'id' => $pegawaiId,
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'nip' => $data['nip'],
                'jabatan' => $data['jabatan'] ?? 'STAFF',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->findByIdForTenant($pegawaiId, $tenantId);
        });
    }
}
