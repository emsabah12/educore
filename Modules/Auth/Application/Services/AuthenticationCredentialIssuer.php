<?php

declare(strict_types=1);

namespace Modules\Auth\Application\Services;

use Illuminate\Support\Facades\Hash;
use Modules\Auth\Application\AuthenticationChannel;
use Modules\Auth\Application\DTO\IssuedAuthenticationCredential;
use Modules\Auth\Authentication\Contracts\AuthenticationRepositoryInterface;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;

final readonly class AuthenticationCredentialIssuer
{
    public function __construct(
        private AuthenticationRepositoryInterface $authRepository,
        private TokenManagerInterface $tokenManager,
        private AuditTrailServiceInterface $auditTrail,
    ) {}

    /**
     * Verify canonical tenant-aware credentials and issue one canonical bearer.
     *
     * The bearer is returned only to trusted server-side callers. Browser-facing
     * adapters must move it directly into server-side custody and must never
     * serialize it into an HTTP response.
     */
    public function issue(
        string $email,
        string $password,
        string $tenantUuid,
        AuthenticationChannel $channel,
    ): ?IssuedAuthenticationCredential {
        $user = $this->authRepository
            ->findByEmailForTenant(
                $email,
                $tenantUuid,
            );

        if (
            $user === null
            || ! Hash::check(
                $password,
                (string) $user['password'],
            )
        ) {
            $this->auditTrail->log(
                'auth.login_failed',
                $channel->failedLoginDescription($email),
                $tenantUuid,
                $user['id'] ?? null,
                [
                    'channel' => $channel->value,
                    'email' => $email,
                ],
            );

            return null;
        }

        $bearerCredential = $this->tokenManager
            ->issueToken(
                (string) $user['id'],
                (string) $user['tenant_id'],
                [
                    'membership_id' => (string) $user['membership_id'],
                ],
            );

        return new IssuedAuthenticationCredential(
            userId: (string) $user['id'],
            name: (string) $user['name'],
            email: (string) $user['email'],
            membershipId: (string) $user['membership_id'],
            tenantId: (string) $user['tenant_id'],
            bearerCredential: $bearerCredential,
            expiresInSeconds: $this->tokenManager
                ->lifetimeInSeconds(),
        );
    }
}
