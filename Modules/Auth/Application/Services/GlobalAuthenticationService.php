<?php

declare(strict_types=1);

namespace Modules\Auth\Application\Services;

use InvalidArgumentException;
use Modules\Auth\Application\AuthenticationChannel;
use Modules\Auth\Application\DTO\AuthenticatedGlobalIdentity;
use Modules\Auth\Authentication\Contracts\AuthenticationRepositoryInterface;
use Modules\Auth\Authentication\Contracts\PasswordVerifierInterface;
use Modules\Core\Governance\Audit\Contracts\AuditTrailServiceInterface;
use Throwable;

final readonly class GlobalAuthenticationService
{
    public function __construct(
        private AuthenticationRepositoryInterface $authRepository,
        private PasswordVerifierInterface $passwordVerifier,
        private AuditTrailServiceInterface $auditTrail,
        private string $dummyPasswordHash,
    ) {
        if (trim($this->dummyPasswordHash) === '') {
            throw new InvalidArgumentException(
                'Global authentication requires a non-empty dummy password hash.',
            );
        }
    }

    /**
     * Verify global User credentials without establishing Membership or
     * Tenant context.
     *
     * Unknown identifiers and malformed credential projections still execute
     * the canonical password verifier against the configured dummy hash.
     *
     * A dummy-hash comparison can never authenticate a User.
     */
    public function authenticate(
        string $identifier,
        string $password,
        AuthenticationChannel $channel,
    ): ?AuthenticatedGlobalIdentity {
        $user = $this->authRepository
            ->findActiveByLoginIdentifier(
                $identifier,
            );

        $hasCanonicalPasswordHash = $user !== null
            && $this->hasCanonicalPasswordHash(
                $user,
            );

        $passwordHash = $hasCanonicalPasswordHash
            ? (string) $user['password_hash']
            : $this->dummyPasswordHash;

        /*
         * Verification deliberately executes for all lookup outcomes:
         *
         * - known User + canonical hash -> verify real hash;
         * - unknown User -> verify dummy hash;
         * - malformed User credential projection -> verify dummy hash.
         *
         * Do not short-circuit before this operation.
         */
        $passwordMatches = $this->passwordVerifier
            ->verify(
                $password,
                $passwordHash,
            );

        /*
         * Authentication succeeds only through a real canonical password hash.
         *
         * Dummy verification exists solely to preserve a less distinguishable
         * verification path. Its result must never become authentication
         * authority.
         */
        if (
            $user === null
            || ! $hasCanonicalPasswordHash
            || ! $passwordMatches
        ) {
            $this->recordFailedAuthentication(
                $identifier,
                $channel,
            );

            return null;
        }

        try {
            return $this->mapIdentity(
                $user,
            );
        } catch (Throwable $exception) {
            /*
             * Malformed trusted identity projection data fails closed.
             *
             * Never report the submitted password, password hash, or raw
             * identifier.
             */
            report($exception);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $projection
     */
    private function hasCanonicalPasswordHash(
        array $projection,
    ): bool {
        $passwordHash = $projection['password_hash']
            ?? null;

        return is_string($passwordHash)
            && $passwordHash !== '';
    }

    /**
     * @param array<string, mixed> $projection
     */
    private function mapIdentity(
        array $projection,
    ): AuthenticatedGlobalIdentity {
        return new AuthenticatedGlobalIdentity(
            userId: $this->requiredString(
                $projection,
                'user_id',
            ),
            personId: $this->requiredString(
                $projection,
                'person_id',
            ),
            name: $this->requiredString(
                $projection,
                'person_name',
            ),
            email: $this->requiredString(
                $projection,
                'email',
            ),
            username: $this->nullableString(
                $projection,
                'username',
            ),
            isSuperadmin: $this->requiredBool(
                $projection,
                'is_superadmin',
            ),
        );
    }

    private function recordFailedAuthentication(
        string $identifier,
        AuthenticationChannel $channel,
    ): void {
        $identifierType = str_contains(
            trim($identifier),
            '@',
        )
            ? 'email'
            : 'username';

        /*
         * Deliberately generic. Never include the raw identifier in either
         * description or metadata.
         */
        $this->auditTrail->log(
            'auth.login_failed',
            'Global authentication failed.',
            null,
            null,
            [
                'channel' => $channel->value,
                'identifier_type' => $identifierType,
            ],
        );
    }

    /**
     * @param array<string, mixed> $projection
     */
    private function requiredString(
        array $projection,
        string $key,
    ): string {
        $value = $projection[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Authentication projection field "%s" must be a string.',
                    $key,
                ),
            );
        }

        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(
                sprintf(
                    'Authentication projection field "%s" must not be empty.',
                    $key,
                ),
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $projection
     */
    private function nullableString(
        array $projection,
        string $key,
    ): ?string {
        $value = $projection[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Authentication projection field "%s" must be null or a string.',
                    $key,
                ),
            );
        }

        $value = trim($value);

        return $value !== ''
            ? $value
            : null;
    }

    /**
     * @param array<string, mixed> $projection
     */
    private function requiredBool(
        array $projection,
        string $key,
    ): bool {
        $value = $projection[$key] ?? null;

        if (! is_bool($value)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Authentication projection field "%s" must be boolean.',
                    $key,
                ),
            );
        }

        return $value;
    }
}
