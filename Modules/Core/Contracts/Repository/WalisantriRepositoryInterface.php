<?php

declare(strict_types=1);

namespace Modules\Core\Contracts\Repository;

interface WalisantriRepositoryInterface
{
    /**
     * Mengambil daftar wali santri terisolasi per tenant beserta paginasi.
     */
    public function getByTenantPaginated(string $tenantId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    /**
     * Mendapatkan detail profil wali santri spesifik di dalam scope tenant yang sah.
     */
    public function findByIdForTenant(string $id, string $tenantId): array;

    /**
     * Mendaftarkan wali santri baru lintas 3 tabel (users, memberships, walisantris).
     */
    public function createForTenant(string $tenantId, array $data): array;
}
