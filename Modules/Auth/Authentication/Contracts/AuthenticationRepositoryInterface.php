<?php

declare(strict_types=1);

namespace Modules\Auth\Authentication\Contracts;

/**
 * Trusted persistence boundary for authentication identity resolution.
 */
interface AuthenticationRepositoryInterface
{
    /**
     * Resolve one active global authentication identity.
     *
     * Tenant, Membership, role, permission, organization, and workspace
     * context MUST NOT participate in global credential verification.
     *
     * During the current staged rollout this method supports canonical email
     * identifiers. Username resolution is added after the users.username
     * persistence contract is introduced.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveByLoginIdentifier(
        string $identifier,
    ): ?array;

    /**
     * Legacy tenant-aware authentication lookup.
     *
     * This remains temporarily available while existing callers are migrated
     * to the global authentication boundary. It MUST NOT be used by new
     * global-login code.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmailForTenant(
        string $email,
        string $tenantUuid,
    ): ?array;
}
