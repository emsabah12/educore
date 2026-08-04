<?php

declare(strict_types=1);

namespace Modules\Core\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Uuid\UuidV7;
use Modules\Core\Tenancy\Contracts\TenantRepositoryInterface;

final class EloquentTenantRepository implements TenantRepositoryInterface
{
    /**
     * Mengambil seluruh tenant menggunakan pagination.
     */
    public function getAllPaginated(
        int $perPage = 15,
    ): LengthAwarePaginator {
        return DB::table('tenants')
            ->select([
                'id',
                'name',
                'subdomain',
                'is_active',
                'created_at',
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Mengambil tenant berdasarkan canonical UUID.
     *
     * @throws ModelNotFoundException
     *
     * @return array<string, mixed>
     */
    public function findById(string $id): array
    {
        $tenant = DB::table('tenants')
            ->where('id', $id)
            ->first();

        if ($tenant === null) {
            $exception = new ModelNotFoundException();

            $exception->setModel(
                'Tenant',
                [$id],
            );

            throw $exception;
        }

        return (array) $tenant;
    }

    /**
     * Membuat tenant baru secara atomik.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        return DB::transaction(
            function () use ($data): array {
                $tenantId = UuidV7::generate();

                DB::table('tenants')->insert([
                    'id' => $tenantId,
                    'name' => (string) $data['name'],
                    'subdomain' => strtolower(
                        (string) $data['subdomain'],
                    ),
                    'is_active' => (bool) (
                        $data['is_active'] ?? true
                    ),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $this->findById($tenantId);
            },
        );
    }

    /**
     * Memperbarui tenant secara atomik.
     *
     * @param array<string, mixed> $data
     *
     * @throws ModelNotFoundException
     *
     * @return array<string, mixed>
     */
    public function update(
        string $id,
        array $data,
    ): array {
        return DB::transaction(
            function () use ($id, $data): array {
                $this->findById($id);

                $updatePayload = [
                    'updated_at' => now(),
                ];

                if (array_key_exists('name', $data)) {
                    $updatePayload['name'] =
                        (string) $data['name'];
                }

                if (array_key_exists('is_active', $data)) {
                    $updatePayload['is_active'] =
                        (bool) $data['is_active'];
                }

                DB::table('tenants')
                    ->where('id', $id)
                    ->update($updatePayload);

                return $this->findById($id);
            },
        );
    }
}
