<?php

declare(strict_types=1);

namespace Modules\Auth\Application\DTO;

/**
 * Authenticated global User/Person projection.
 *
 * This DTO deliberately contains no password hash, Membership context,
 * Tenant context, role, or permission state.
 */
final readonly class AuthenticatedGlobalIdentity
{
    public function __construct(
        public string $userId,
        public string $personId,
        public string $name,
        public string $email,
        public ?string $username,
        public bool $isSuperadmin,
    ) {}
}
