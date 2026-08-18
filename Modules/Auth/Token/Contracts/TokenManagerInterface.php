<?php

declare(strict_types=1);

namespace Modules\Auth\Token\Contracts;

/**
 * Contract untuk penerbitan dan validasi authentication token.
 */
interface TokenManagerInterface
{
    /**
     * Menerbitkan token terenkripsi yang membawa canonical identity
     * dan tenant context.
     *
     * Core claims tidak boleh ditimpa oleh custom claims.
     *
     * @param  array<string, mixed>  $customClaims
     */
    public function issueToken(
        string $userUuid,
        string $tenantUuid,
        array $customClaims = [],
    ): string;

    /**
     * Memvalidasi token dan mengembalikan payload jika token sah
     * serta belum kedaluwarsa.
     *
     * @return array<string, mixed>|null
     */
    public function validateAndExtract(string $token): ?array;

    /**
     * Resolve canonical token expiration metadata for revocation lifecycle.
     *
     * This method validates the encrypted envelope and required core claims but
     * MUST NOT be used as an authentication decision and does not require the
     * token to still be unexpired or unrevoked.
     */
    public function expiresAtForRevocation(string $token): ?int;

    /**
     * Masa aktif token dalam detik.
     *
     * Nilai ini menjadi sumber kebenaran bagi token payload
     * dan metadata response authentication.
     */
    public function lifetimeInSeconds(): int;
}
