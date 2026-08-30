<?php

declare(strict_types=1);

namespace Modules\Auth\Application\Services;

use InvalidArgumentException;
use Modules\Auth\Application\DTO\IssuedIdentityCredential;
use Modules\Auth\Token\Contracts\TokenManagerInterface;

final readonly class IdentityCredentialIssuer
{
    public function __construct(
        private TokenManagerInterface $tokenManager,
    ) {}

    /**
     * Issue one identity-scoped bearer for an already-authenticated User.
     *
     * Credential verification is intentionally outside this service. This
     * boundary must never infer or establish Membership/Tenant context.
     */
    public function issue(
        string $userId,
    ): IssuedIdentityCredential {
        $userId = trim($userId);

        if ($userId === '') {
            throw new InvalidArgumentException(
                'Identity credential issuance requires a non-empty User identifier.',
            );
        }

        $bearerCredential = $this->tokenManager
            ->issueIdentityToken(
                $userId,
            );

        return new IssuedIdentityCredential(
            bearerCredential: $bearerCredential,
            expiresInSeconds: $this->tokenManager
                ->lifetimeInSeconds(),
        );
    }
}
