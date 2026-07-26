<?php

declare(strict_types=1);

namespace Modules\Academic\Repositories;

use Modules\Academic\Contracts\StudentRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Support\Uuid\UuidV7;
use Illuminate\Support\Facades\Hash;

final class EloquentStudentRepository implements StudentRepositoryInterface
{
    /**
     * Menampilkan daftar student berlingkup tenant tertentu lengkap dengan relasi nama kelas via JOIN.
     */
    public function getByTenantPaginated(string $tenantId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return DB::table('students')
            ->join('memberships', 'students.membership_id', '=', 'memberships.id')
            ->join('users', 'memberships.user_id', '=', 'users.id')
            ->join('academic_classes', 'students.class_id', '=', 'academic_classes.id')
            ->select([
                'students.id as student_id',
                'students.tenant_id',
                'students.nis',
                'students.nisn',
                'users.name as nama',
                'users.email',
                'academic_classes.name as nama_kelas',
                'academic_classes.tingkat',
                'memberships.status as status_aktif',
                'students.created_at'
            ])
            ->where('students.tenant_id', '=', $tenantId)
            ->whereNull('students.deleted_at')
            ->orderBy('academic_classes.name', 'asc')
            ->orderBy('users.name', 'asc')
            ->paginate($perPage);
    }

    /**
     * Mengambil spesifik detail data student terproteksi isolasi tenant.
     */
    public function findByIdForTenant(string $id, string $tenantId): array
    {
        $student = DB::table('students')
            ->join('memberships', 'students.membership_id', '=', 'memberships.id')
            ->join('users', 'memberships.user_id', '=', 'users.id')
            ->join('academic_classes', 'students.class_id', '=', 'academic_classes.id')
            ->select([
                'students.id as student_id',
                'students.tenant_id',
                'students.membership_id',
                'students.class_id',
                'students.nis',
                'students.nisn',
                'users.id as user_id',
                'users.name as nama',
                'users.email',
                'academic_classes.name as nama_kelas',
                'memberships.status as status_aktif',
                'students.created_at'
            ])
            ->where('students.id', '=', $id)
            ->where('students.tenant_id', '=', $tenantId)
            ->whereNull('students.deleted_at')
            ->first();

        if (! $student) {
            throw new ModelNotFoundException(sprintf('Data student dengan ID %s tidak ditemukan di lembaga ini.', $id));
        }

        return (array) $student;
    }

    /**
     * Mendaftarkan profil siswa secara transaksional penuh lintas 3 tabel inti platform.
     */
    public function createForTenant(string $tenantId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $userId = UuidV7::generate();
            $membershipId = UuidV7::generate();
            $studentId = UuidV7::generate();

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
                'email' => $data['email'] ?? 'student.' . strtolower(UuidV7::generate()) . '@educore.id',
                'password' => Hash::make('P@sswordstudent2026'),
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. Jembatani keanggotaan institusi ke tabel 'memberships'
            DB::table('memberships')->insert([
                'id' => $membershipId,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'role' => 'student',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 4. Masukkan data ke profil ekstensi tabel 'students'
            DB::table('students')->insert([
                'id' => $studentId,
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'class_id' => $data['class_id'],
                'nis' => $data['nis'] ?? null,
                'nisn' => $data['nisn'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->findByIdForTenant($studentId, $tenantId);
        });
    }
}
