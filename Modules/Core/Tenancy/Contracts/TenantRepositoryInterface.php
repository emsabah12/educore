<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

interface TenantRepositoryInterface
{
    /**
     * Mengambil daftar seluruh penyewa (tenants) terdaftar dengan sistem paginasi.
     *
     * @param  int  $perPage  Jumlah baris per halaman
     */
    public function getAllPaginated(int $perPage = 15): LengthAwarePaginator;

    /**
     * Mencari data entitas penyewa berdasarkan ID UUIDv7.
     *
     * @param  string  $id  UUIDv7 penyewa
     *
     * @throws ModelNotFoundException
     */
    public function findById(string $id): array;

    /**
     * Mendaftarkan lembaga penyewa baru ke dalam sistem basis data.
     *
     * @param  array  $data  Data atribut tenant
     * @return array Data entitas yang berhasil dibuat
     */
    public function create(array $data): array;

    /**
     * Memperbarui informasi data penyewa.
     *
     * @param  string  $id  UUIDv7 penyewa
     * @param  array  $data  Atribut pembaruan
     * @return array Data terbaru hasil pembaruan
     */
    public function update(string $id, array $data): array;
}
