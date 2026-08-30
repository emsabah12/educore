<?php

declare(strict_types=1);

namespace Modules\Auth\Token\Contracts;

/**
 * Contract for canonical authentication credential issuance and validation.
 *
 * Authentication credentials are separated by context:
 *
 * - Identity Credential proves the authenticated global User only.
 * - Membership Credential proves the selected User/Membership/Tenant context.
 */
interface TokenManagerInterface
{
    /**
     * Issue a canonical Identity Credential.
     *
     * Identity Credentials contain only:
     *
     * - credential_type=identity
     * - user_id
     * - expires_at
     */
    public function issueIdentityToken(
        string $userUuid,
    ): string;

    /**
     * Issue a canonical Membership Credential.
     *
     * Membership Credentials contain only:
     *
     * - credential_type=membership
     * - user_id
     * - tenant_id
     * - membership_id
     * - expires_at
     *
     * Role and permission state MUST NOT be embedded into this credential.
     */
    public function issueMembershipToken(
        string $userUuid,
        string $tenantUuid,
        string $membershipUuid,
    ): string;

    /**
     * Issue the existing legacy Tenant-aware credential.
     *
     * This API remains temporarily available while existing callers are
     * migrated to issueMembershipToken().
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
     * Resolve canonical credential expiration metadata for revocation.
     *
     * This validates the encrypted envelope and structural claims but MUST
     * NOT be used as an authentication decision.
     */
    public function expiresAtForRevocation(
        string $token,
    ): ?int;

    /**
     * Canonical credential lifetime in seconds.
     */
    public function lifetimeInSeconds(): int;
}
