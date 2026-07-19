<?php

declare(strict_types=1);

namespace Modules\Core\Contracts\Repository;

interface AcademicPeriodRepositoryInterface
{
    /**
     * Mengambil daftar tahun ajaran per-tenant.
     */
    public function getYearsPaginated(string $tenantId, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    /**
     * Mendaftarkan tahun ajaran baru dan mengelola status keaktifannya.
     */
    public function createYearForTenant(string $tenantId, array $data): array;

    /**
     * Mendaftarkan semester baru di bawah tahun ajaran tertentu.
     */
    public function createSemesterForTenant(string $tenantId, string $yearId, array $data): array;

    /**
     * Mengaktifkan satu tahun ajaran tertentu dan menonaktifkan yang lain secara otomatis.
     */
    public function activateYear(string $tenantId, string $yearId): bool;

    /**
     * Mengaktifkan satu semester tertentu dan menonaktifkan yang lain secara otomatis.
     */
    public function activateSemester(string $tenantId, string $semesterId): bool;
}
