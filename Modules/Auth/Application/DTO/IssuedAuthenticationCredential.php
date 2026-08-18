<?php

declare(strict_types=1);

namespace Modules\Auth\Application\DTO;

final readonly class IssuedAuthenticationCredential
{
    public function __construct(
        public string $userId,
        public string $name,
        public string $email,
        public string $membershipId,
        public string $tenantId,
        public string $bearerCredential,
        public int $expiresInSeconds,
    ) {}
}
