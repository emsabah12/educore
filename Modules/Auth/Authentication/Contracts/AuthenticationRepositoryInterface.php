<?php

declare(strict_types=1);

namespace Modules\Auth\Authentication\Contracts;

/**
 * Interface AuthenticationRepositoryInterface
 * * Kontrak platform untuk manajemen pencarian entitas pengguna terisolasi.
 */
interface AuthenticationRepositoryInterface
{
    /**
     * Cari user berdasarkan email yang terikat ketat dengan UUID Tenant.
     * 
     * @param string $email
     * @param string $tenantUuid
     * @return array<string, mixed>|null
     */
    public function findByEmailForTenant(string $email, string $tenantUuid): ?array;

    /**
     * Cari user berdasarkan UUID v7 untuk kebutuhan validasi runtime session.
     * 
     * @param string $userUuid
     * @return array<string, mixed>|null
     */
    public function findByUserUuid(string $userUuid): ?array;
}
