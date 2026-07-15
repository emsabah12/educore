<?php

declare(strict_types=1);

namespace Modules\Core\Contracts\Auth;

/**
 * Interface TokenManagerInterface
 * * Kontrak platform untuk penerbitan dan pembedahan token otentikasi terpadu.
 */
interface TokenManagerInterface
{
    /**
     * Menerbitkan token unik (JWT/Opaque) yang membawa klaim identitas tenant.
     * * @param string $userUuid
     * @param string $tenantUuid
     * @param array<string, mixed> $customClaims
     * @return string
     */
    public function issueToken(string $userUuid, string $tenantUuid, array $customClaims = []): string;

    /**
     * Validasi token runtime dan ekstrak seluruh klaim payload di dalamnya (Fail-Fast).
     * * @param string $token
     * @return array<string, mixed>
     */
    public function validateAndExtract(string $token): array;
}
