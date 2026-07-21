<?php

declare(strict_types=1);

namespace Modules\Academic\Repositories;

use Modules\Academic\Contracts\Repository\AcademicSubjectRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Support\Uuid\UuidV7;

final class EloquentAcademicSubjectRepository implements AcademicSubjectRepositoryInterface
{

    public function allByTenant(string $tenantId): array
    {
        return DB::table('academic_subjects')->where('tenant_id', $tenantId)->get()->toArray();
    }

    public function findByTenant(string $tenantId, string $id): ?object
    {
        return DB::table('academic_subjects')->where('tenant_id', $tenantId)->where('id', $id)->first();
    }


    public function getByTenantPaginated(string $tenantId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return DB::table('academic_subjects')
            ->select(['id', 'tenant_id', 'name', 'code', 'category', 'is_active', 'created_at'])
            ->where('tenant_id', '=', $tenantId)
            ->whereNull('deleted_at')
            ->orderBy('code', 'asc')
            ->paginate($perPage);
    }

    public function findByIdForTenant(string $id, string $tenantId): array
    {
        $subject = DB::table('academic_subjects')
            ->where('id', '=', $id)
            ->where('tenant_id', '=', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if (! $subject) {
            throw new ModelNotFoundException(sprintf('Mata Pelajaran dengan ID %s tidak ditemukan di lembaga ini.', $id));
        }

        return (array) $subject;
    }

    public function createForTenant(string $tenantId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $id = UuidV7::generate();

            DB::table('academic_subjects')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'code' => strtoupper($data['code']),
                'category' => $data['category'] ?? 'NASIONAL',
                'is_active' => $data['is_active'] ?? true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->findByIdForTenant($id, $tenantId);
        });
    }
}
