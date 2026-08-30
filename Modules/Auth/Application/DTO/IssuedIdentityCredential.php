<?php

declare(strict_types=1);

namespace Modules\Auth\Application\DTO;

/**
 * Stateless identity bearer transport result.
 *
 * Global identity/account projection remains separate in
 * AuthenticatedGlobalIdentity.
 *
 * This DTO deliberately contains no User, Tenant, Membership, role,
 * permission, or authorization state.
 */
final readonly class IssuedIdentityCredential
{
    public function __construct(
        public string $bearerCredential,
        public int $expiresInSeconds,
    ) {}
}
