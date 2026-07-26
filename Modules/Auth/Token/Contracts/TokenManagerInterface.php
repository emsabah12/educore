<?php

declare(strict_types=1);

namespace Modules\Auth\Token\Contracts;

/**
 * Interface TokenManagerInterface
 * * Kontrak platform untuk penerbitan dan pembedahan token otentikasi terpadu.
 */
interface TokenManagerInterface
{
    /**
     * Menerbitkan token unik (JWT/Opaque) yang membawa klaim identitas tenant.
     * 
     * @param string $userUuid
     * @param string $tenantUuid
     * @param array $customClaims Klaim kustom tambahan yang ingin disertakan dalam token.
     * @return string
     */
    public function issueToken(string $userUuid, string $tenantUuid, array $customClaims = []): string;

    /**
     * Validasi token runtime dan ekstrak seluruh klaim payload di dalamnya (Fail-Fast).
     * @param string $token
     * @return array|null Mengembalikan array payload [user_id, tenant_id] jika valid, atau null jika tidak sah/expired.
     */
    public function validateAndExtract(string $token): ?array;
}
