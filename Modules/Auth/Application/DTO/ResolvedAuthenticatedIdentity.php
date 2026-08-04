<?php

declare(strict_types=1);

namespace Modules\Auth\Application\DTO;

use Modules\Core\Identity\Models\User;

final readonly class ResolvedAuthenticatedIdentity
{
    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(
        public User $user,
        public string $userId,
        public array $claims,
    ) {}

    public function stringClaim(
        string $claim,
    ): ?string {
        $value = $this->claims[$claim] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== ''
            ? $value
            : null;
    }
}
