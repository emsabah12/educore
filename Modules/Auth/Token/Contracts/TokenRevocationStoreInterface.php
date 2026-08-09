<?php

declare(strict_types=1);

namespace Modules\Auth\Token\Contracts;

interface TokenRevocationStoreInterface
{
    /**
     * Menandai bearer token tertentu sebagai revoked sampai
     * masa berlaku token tersebut berakhir.
     *
     * Implementation tidak boleh menyimpan raw bearer token.
     */
    public function revoke(
        string $token,
        int $expiresAt,
    ): void;

    /**
     * Menentukan apakah bearer token tertentu telah direvoke
     * dan revocation record tersebut masih relevan.
     */
    public function isRevoked(
        string $token,
    ): bool;
}
