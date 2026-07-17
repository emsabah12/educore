<?php

declare(strict_types=1);

namespace Modules\Core\Contracts\Repository;

interface WalisantriSantriRepositoryInterface
{
    /**
     * Menautkan seorang santri ke wali santri di dalam scope tenant yang aman.
     */
    public function attachSantri(string $tenantId, string $walisantriId, string $santriId, string $hubungan): bool;

    /**
     * Memutuskan hubungan tautan antara santri dan wali santri.
     */
    public function detachSantri(string $tenantId, string $walisantriId, string $santriId): bool;

    /**
     * Mendapatkan daftar santri/anak yang diasuh oleh wali santri spesifik.
     */
    public function getSantriByWalisantri(string $tenantId, string $walisantriId): array;
}
