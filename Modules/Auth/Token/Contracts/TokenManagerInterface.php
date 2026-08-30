<?php

declare(strict_types=1);

namespace Modules\Auth\Token\Contracts;

/**
 * Contract for canonical authentication credential issuance and validation.
 *
 * Authentication credentials are intentionally separated by context:
 *
 * - Identity Credential proves the authenticated global User only.
 * - Membership Credential carries Tenant/Membership context and is migrated
 *   independently from the global identity boundary.
 */
interface TokenManagerInterface
{
    /**
     * Issue a canonical Identity Credential.
     *
     * Identity Credentials MUST contain only:
     *
     * - credential_type=identity
     * - user_id
     * - expires_at
     *
     * They MUST NOT contain Tenant, Membership, role, or permission context.
     */
    public function issueIdentityToken(
        string $userUuid,
    ): string;

    /**
     * Issue the existing Tenant-aware credential.
     *
     * This API remains temporarily available while Membership Credential
     * issuance is migrated to its explicit typed contract.
     *
     * Core claims cannot be overridden by custom claims.
     *
     * @param  array<string, mixed>  $customClaims
     */
    public function issueToken(
        string $userUuid,
        string $tenantUuid,
        array $customClaims = [],
    ): string;

    /**
     * Validate a credential and return its canonical payload when valid
     * and unexpired.
     *
     * @return array<string, mixed>|null
     */
    public function validateAndExtract(
        string $token,
    ): ?array;

    /**
     * Resolve canonical token expiration metadata for revocation lifecycle.
     *
     * This validates the encrypted envelope and required core claims but MUST
     * NOT be used as an authentication decision. Expired or already-revoked
     * credentials can still expose their expiration metadata for logout and
     * revocation persistence.
     */
    public function expiresAtForRevocation(
        string $token,
    ): ?int;

    /**
     * Canonical credential lifetime in seconds.
     */
    public function lifetimeInSeconds(): int;
}
