<?php

declare(strict_types=1);

namespace Modules\Core\Repositories;

use Modules\Core\Contracts\Repository\SantriRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Support\Uuid\UuidV7;
use Illuminate\Support\Facades\Hash;

final class EloquentSantriRepository implements SantriRepositoryInterface
{
    /**
     * Menampilkan daftar santri berlingkup tenant tertentu lengkap dengan relasi nama kelas via JOIN.
     */
    public function getByTenantPaginated(string $tenantId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return DB::table('santris')
            ->join('memberships', 'santris.membership_id', '=', 'memberships.id')
            ->join('users', 'memberships.user_id', '=', 'users.id')
            ->join('academic_classes', 'santris.class_id', '=', 'academic_classes.id')
            ->select([
                'santris.id as santri_id',
                'santris.tenant_id',
                'santris.nis',
                'santris.nisn',
                'users.name as nama',
                'users.email',
                'academic_classes.name as nama_kelas',
                'academic_classes.tingkat',
                'memberships.status as status_aktif',
                'santris.created_at'
            ])
            ->where('santris.tenant_id', '=', $tenantId)
            ->whereNull('santris.deleted_at')
            ->orderBy('academic_classes.name', 'asc')
            ->orderBy('users.name', 'asc')
            ->paginate($perPage);
    }

    /**
     * Mengambil spesifik detail data santri terproteksi isolasi tenant.
     */
    public function findByIdForTenant(string $id, string $tenantId): array
    {
        $santri = DB::table('santris')
            ->join('memberships', 'santris.membership_id', '=', 'memberships.id')
            ->join('users', 'memberships.user_id', '=', 'users.id')
            ->join('academic_classes', 'santris.class_id', '=', 'academic_classes.id')
            ->select([
                'santris.id as santri_id',
                'santris.tenant_id',
                'santris.membership_id',
                'santris.class_id',
                'santris.nis',
                'santris.nisn',
                'users.id as user_id',
                'users.name as nama',
                'users.email',
                'academic_classes.name as nama_kelas',
                'memberships.status as status_aktif',
                'santris.created_at'
            ])
            ->where('santris.id', '=', $id)
            ->where('santris.tenant_id', '=', $tenantId)
            ->whereNull('santris.deleted_at')
            ->first();

        if (! $santri) {
            throw new ModelNotFoundException(sprintf('Data santri dengan ID %s tidak ditemukan di lembaga ini.', $id));
        }

        return (array) $santri;
    }

    /**
     * Mendaftarkan profil siswa secara transaksional penuh lintas 3 tabel inti platform.
     */
    public function createForTenant(string $tenantId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $userId = UuidV7::generate();
            $membershipId = UuidV7::generate();
            $santriId = UuidV7::generate();

            // 1. Validasi keberadaan kelas di bawah tenant yang sama sebelum diproses
            $classExists = DB::table('academic_classes')
                ->where('id', '=', $data['class_id'])
                ->where('tenant_id', '=', $tenantId)
                ->whereNull('deleted_at')
                ->exists();

            if (! $classExists) {
                throw new ModelNotFoundException('Target kelas akademik tidak valid atau tidak terdaftar di lembaga ini.');
            }

            // 2. Tanam data identitas ke tabel 'users'
            DB::table('users')->insert([
                'id' => $userId,
                'name' => $data['nama'],
                'email' => $data['email'] ?? 'santri.' . strtolower(UuidV7::generate()) . '@educore.id',
                'password' => Hash::make('P@sswordSantri2026'),
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. Jembatani keanggotaan institusi ke tabel 'memberships'
            DB::table('memberships')->insert([
                'id' => $membershipId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'role' => 'SANTRI',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 4. Masukkan data ke profil ekstensi tabel 'santris'
            DB::table('santris')->insert([
                'id' => $santriId,
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'class_id' => $data['class_id'],
                'nis' => $data['nis'] ?? null,
                'nisn' => $data['nisn'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->findByIdForTenant($santriId, $tenantId);
        });
    }
}
