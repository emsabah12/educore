<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Contracts;

/**
 * Trusted authorization context untuk current application lifecycle.
 *
 * Context ini merepresentasikan:
 *
 * User
 *   ↓
 * Membership
 *   ↓
 * Tenant
 *
 * Context tidak bertanggung jawab melakukan database lookup.
 * Validasi ownership dan status membership dilakukan oleh
 * AuthorizationService / authorization pipeline.
 */
interface AuthorizationContextInterface
{
    /**
     * Mendapatkan ID user yang sedang diauthorisasi.
     */
    public function userId(): string;

    /**
     * Mendapatkan ID tenant yang sedang aktif.
     */
    public function tenantId(): string;

    /**
     * Mendapatkan ID membership yang sedang digunakan
     * untuk authorization.
     */
    public function membershipId(): string;
}
