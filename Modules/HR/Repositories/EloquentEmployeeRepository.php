<?php

declare(strict_types=1);

namespace Modules\HR\Repositories;

use Modules\HR\Contracts\EmployeeRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Support\Uuid\UuidV7;
use Illuminate\Support\Facades\Hash;

final class EloquentEmployeeRepository implements EmployeeRepositoryInterface
{
    /**
     * Mengambil daftar employee terisolasi berdasarkan scope tenant_id dengan teknik JOIN Relasional.
     */
    public function getByTenantPaginated(string $tenantId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return DB::table('employees')
            ->join('memberships', 'employees.membership_id', '=', 'memberships.id')
            ->join('users', 'memberships.user_id', '=', 'users.id')
            ->select([
                'employees.id as employee_id',
                'employees.tenant_id',
                'employees.nip',
                'employees.jabatan',
                'users.name as nama',
                'users.email',
                'memberships.status as status_aktif',
                'employees.created_at'
            ])
            ->where('employees.tenant_id', '=', $tenantId)
            ->orderBy('employees.created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Mendapatkan spesifik detail employee dengan kawalan ketat cross-tenant block check via JOIN.
     */
    public function findByIdForTenant(string $id, string $tenantId): array
    {
        $employee = DB::table('employees')
            ->join('memberships', 'employees.membership_id', '=', 'memberships.id')
            ->join('users', 'memberships.user_id', '=', 'users.id')
            ->select([
                'employees.id as employee_id',
                'employees.tenant_id',
                'employees.membership_id',
                'employees.nip',
                'employees.jabatan',
                'users.id as user_id',
                'users.name as nama',
                'users.email',
                'memberships.status as status_aktif',
                'employees.created_at'
            ])
            ->where('employees.id', '=', $id)
            ->where('employees.tenant_id', '=', $tenantId)
            ->first();

        if (! $employee) {
            throw new ModelNotFoundException(
                sprintf('Data staf employee dengan ID %s tidak ditemukan pada lembaga ini.', $id)
            );
        }

        return (array) $employee;
    }

    /**
     * Menyimpan data employee secara atomik lintas 3 tabel (users -> memberships -> employees).
     */
    public function createForTenant(string $tenantId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $userId = UuidV7::generate();
            $membershipId = UuidV7::generate();
            $employeeId = UuidV7::generate();

            // 1. Amankan data di tabel 'users' terlebih dahulu
            // Menggunakan password default aman terenkripsi yang wajib diubah saat login pertama
            DB::table('users')->insert([
                'id' => $userId,
                'name' => $data['nama'],
                'email' => $data['email'] ?? strtolower(str_replace(' ', '', $data['nama'])) . '@educore.id',
                'password' => Hash::make('P@sswordemployee2026'),
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2. Jembatani keanggotaan di tabel 'memberships'
            DB::table('memberships')->insert([
                'id' => $membershipId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'role' => 'employee',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. Masukkan data spesifik profesi ke tabel utama 'employees'
            DB::table('employees')->insert([
                'id' => $employeeId,
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'nip' => $data['nip'],
                'jabatan' => $data['jabatan'] ?? 'STAFF',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->findByIdForTenant($employeeId, $tenantId);
        });
    }
}
