<?php

declare(strict_types=1);

namespace Modules\Academic\Repositories;

use Modules\Academic\Contracts\Repository\AcademicPeriodRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Support\Uuid\UuidV7;

final class EloquentAcademicPeriodRepository implements AcademicPeriodRepositoryInterface
{

    public function allActiveByTenant(string $tenantId): array
    {
        return DB::table('academic_semesters')
            ->join('academic_years', 'academic_semesters.academic_year_id', '=', 'academic_years.id')
            ->where('academic_semesters.tenant_id', $tenantId)
            ->where('academic_semesters.is_active', true)
            ->select('academic_semesters.id as period_id', 'academic_years.name as year_name', 'academic_semesters.semester as semester')
            ->get()
            ->toArray();
    }

    public function findByTenant(string $tenantId, string $id): ?object
    {
        return DB::table('academic_semesters')->where('tenant_id', $tenantId)->where('id', $id)->first();
    }

    public function getYearsPaginated(string $tenantId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return DB::table('academic_years')
            ->where('tenant_id', '=', $tenantId)
            ->whereNull('deleted_at')
            ->orderBy('name', 'desc')
            ->paginate($perPage);
    }

    public function createYearForTenant(string $tenantId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $yearId = UuidV7::generate();
            $isActive = (bool) ($data['is_active'] ?? false);

            if ($isActive) {
                // Protokol Auto-Deactivate Tahun Ajaran Lama
                DB::table('academic_years')
                    ->where('tenant_id', '=', $tenantId)
                    ->where('is_active', '=', true)
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }

            DB::table('academic_years')->insert([
                'id' => $yearId,
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'is_active' => $isActive,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return (array) DB::table('academic_years')->where('id', '=', $yearId)->first();
        });
    }

    public function createSemesterForTenant(string $tenantId, string $yearId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $yearId, $data) {
            // Validasi kepemilikan tahun ajaran terhadap tenant
            $yearExists = DB::table('academic_years')
                ->where('id', '=', $yearId)
                ->where('tenant_id', '=', $tenantId)
                ->whereNull('deleted_at')
                ->exists();

            if (! $yearExists) {
                throw new ModelNotFoundException('Tahun ajaran tidak valid atau tidak terdaftar di lembaga ini.');
            }

            $semesterId = UuidV7::generate();
            $isActive = (bool) ($data['is_active'] ?? false);

            if ($isActive) {
                // Protokol Auto-Deactivate Semester Lama
                DB::table('academic_semesters')
                    ->where('tenant_id', '=', $tenantId)
                    ->where('is_active', '=', true)
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }

            DB::table('academic_semesters')->insert([
                'id' => $semesterId,
                'tenant_id' => $tenantId,
                'academic_year_id' => $yearId,
                'name' => $data['name'],
                'type' => strtoupper($data['type']),
                'is_active' => $isActive,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return (array) DB::table('academic_semesters')->where('id', '=', $semesterId)->first();
        });
    }

    public function activateYear(string $tenantId, string $yearId): bool
    {
        return DB::transaction(function () use ($tenantId, $yearId) {
            $exists = DB::table('academic_years')
                ->where('id', '=', $yearId)
                ->where('tenant_id', '=', $tenantId)
                ->whereNull('deleted_at')
                ->exists();

            if (! $exists) {
                throw new ModelNotFoundException('Data tahun ajaran tidak ditemukan.');
            }

            // Nonaktifkan semua tahun ajaran lain di tenant yang sama
            DB::table('academic_years')
                ->where('tenant_id', '=', $tenantId)
                ->where('is_active', '=', true)
                ->update(['is_active' => false, 'updated_at' => now()]);

            // Aktifkan tahun ajaran target
            $affected = DB::table('academic_years')
                ->where('id', '=', $yearId)
                ->where('tenant_id', '=', $tenantId)
                ->update(['is_active' => true, 'updated_at' => now()]);

            return $affected > 0;
        });
    }

    public function activateSemester(string $tenantId, string $semesterId): bool
    {
        return DB::transaction(function () use ($tenantId, $semesterId) {
            $exists = DB::table('academic_semesters')
                ->where('id', '=', $semesterId)
                ->where('tenant_id', '=', $tenantId)
                ->whereNull('deleted_at')
                ->exists();

            if (! $exists) {
                throw new ModelNotFoundException('Data semester tidak ditemukan.');
            }

            // Nonaktifkan semua semester lain di tenant yang sama
            DB::table('academic_semesters')
                ->where('tenant_id', '=', $tenantId)
                ->where('is_active', '=', true)
                ->update(['is_active' => false, 'updated_at' => now()]);

            // Aktifkan semester target
            $affected = DB::table('academic_semesters')
                ->where('id', '=', $semesterId)
                ->where('tenant_id', '=', $tenantId)
                ->update(['is_active' => true, 'updated_at' => now()]);

            return $affected > 0;
        });
    }
}
