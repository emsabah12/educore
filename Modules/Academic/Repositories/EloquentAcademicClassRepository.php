<?php

declare(strict_types=1);

namespace Modules\Academic\Repositories;

use Modules\Academic\Contracts\Repository\AcademicClassRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Support\Uuid\UuidV7;

final class EloquentAcademicClassRepository implements AcademicClassRepositoryInterface
{
    public function getByTenantPaginated(string $tenantId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return DB::table('academic_classes')
            ->select(['id', 'tenant_id', 'name', 'code', 'tingkat', 'is_active', 'created_at'])
            ->where('tenant_id', '=', $tenantId)
            ->whereNull('deleted_at')
            ->orderBy('name', 'asc')
            ->paginate($perPage);
    }

    public function findByIdForTenant(string $id, string $tenantId): array
    {
        $class = DB::table('academic_classes')
            ->where('id', '=', $id)
            ->where('tenant_id', '=', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if (! $class) {
            throw new ModelNotFoundException(sprintf('Kelas dengan ID %s tidak ditemukan di lembaga ini.', $id));
        }

        return (array) $class;
    }

    public function createForTenant(string $tenantId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $id = UuidV7::generate();

            DB::table('academic_classes')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'tingkat' => $data['tingkat'],
                'is_active' => $data['is_active'] ?? true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->findByIdForTenant($id, $tenantId);
        });
    }
}
