<?php

declare(strict_types=1);

namespace Modules\Core\Repositories;

use Modules\Core\Contracts\Repository\TenantRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class EloquentTenantRepository implements TenantRepositoryInterface
{
    /**
     * Mengambil seluruh data penyewa dengan paginasi terstruktur.
     */
    public function getAllPaginated(int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        // Menggunakan raw query builder / model Eloquent langsung demi efisiensi
        return DB::table('tenants')
            ->select(['id', 'name', 'subdomain', 'is_active', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Mencari data entitas penyewa secara spesifik berdasarkan ID.
     */
    public function findById(string $id): array
    {
        $tenant = DB::table('tenants')
            ->where('id', '=', $id)
            ->first();

        if (! $tenant) {
            throw new ModelNotFoundException(sprintf('Tenant dengan ID %s tidak ditemukan.', $id));
        }

        return (array) $tenant;
    }

    /**
     * Mendaftarkan lembaga penyewa baru menggunakan proteksi transaksi database.
     */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $id = \Modules\Core\Support\Uuid\UuidV7::generate();

            DB::table('tenants')->insert([
                'id' => $id,
                'name' => $data['name'],
                'subdomain' => strtolower($data['subdomain']),
                'is_active' => $data['is_active'] ?? true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->findById($id);
        });
    }

    /**
     * Memperbarui status/informasi penyewa secara aman.
     */
    public function update(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data) {
            // Validasi keberadaan data terlebih dahulu
            $this->findById($id);

            $updatePayload = [];
            if (isset($data['name'])) {
                $updatePayload['name'] = $data['name'];
            }
            if (isset($data['is_active'])) {
                $updatePayload['is_active'] = $data['is_active'];
            }
            $updatePayload['updated_at'] = now();

            DB::table('tenants')
                ->where('id', '=', $id)
                ->update($updatePayload);

            return $this->findById($id);
        });
    }
}
