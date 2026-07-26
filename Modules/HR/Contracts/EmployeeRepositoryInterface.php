<?php

declare(strict_types=1);

namespace Modules\HR\Contracts;

interface EmployeeRepositoryInterface
{
    /**
     * Mengambil seluruh data staf pegawai yang terisolasi per lembaga/tenant tertentu.
     *
     * @param string $tenantId UUIDv7 lembaga sekolah
     * @param int $perPage Paginasi per halaman
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getByTenantPaginated(string $tenantId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    /**
     * Mencari data detil pegawai spesifik di dalam lingkup tenant yang sah.
     *
     * @param string $id UUIDv7 record pegawai
     * @param string $tenantId UUIDv7 lembaga sekolah
     * @return array
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findByIdForTenant(string $id, string $tenantId): array;

    /**
     * Mendaftarkan profil pegawai baru terikat pada suatu tenant.
     *
     * @param string $tenantId UUIDv7 lembaga sekolah
     * @param array $data Atribut profil pegawai
     * @return array Data yang berhasil tersimpan
     */
    public function createForTenant(string $tenantId, array $data): array;
}
