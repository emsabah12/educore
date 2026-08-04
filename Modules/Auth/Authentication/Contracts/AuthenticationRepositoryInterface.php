<?php

declare(strict_types=1);

namespace Modules\Auth\Authentication\Contracts;

/**
 * Contract untuk pencarian authentication identity dalam tenant context.
 */
interface AuthenticationRepositoryInterface
{
    /**
     * Cari active global identity yang memiliki active membership
     * pada tenant tertentu.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmailForTenant(
        string $email,
        string $tenantUuid,
    ): ?array;
}
