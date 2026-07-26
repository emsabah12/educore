<?php

declare(strict_types=1);

namespace Modules\Academic\Contracts;

interface StudentRepositoryInterface
{
    /**
     * Mengambil daftar santri/siswa yang terisolasi per lembaga dengan informasi kelasnya.
     */
    public function getByTenantPaginated(string $tenantId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    /**
     * Mendapatkan detail profil santri spesifik di dalam scope tenant yang sah.
     */
    public function findByIdForTenant(string $id, string $tenantId): array;

    /**
     * Mendaftarkan profil santri baru terikat lintas 3 tabel (users, memberships, santris).
     */
    public function createForTenant(string $tenantId, array $data): array;
}
