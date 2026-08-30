<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Auth\Token\Contracts\TokenRevocationStoreInterface;
use Throwable;

final class DeterministicTokenManager implements TokenManagerInterface
{
    /**
     * Canonical credential lifetime.
     *
     * Two hours = 7,200 seconds.
     */
    private const TOKEN_LIFETIME = 7200;

    private const CREDENTIAL_TYPE_IDENTITY = 'identity';

    public function __construct(
        private readonly TokenRevocationStoreInterface $revocationStore,
    ) {}

    public function lifetimeInSeconds(): int
    {
        return self::TOKEN_LIFETIME;
    }

    /**
     * Issue a Tenant-independent canonical Identity Credential.
     *
     * No arbitrary claims are accepted here intentionally. This prevents
     * Tenant, Membership, role, or permission context from leaking into the
     * global Identity Credential.
     *
     * @throws JsonException
     */
    public function issueIdentityToken(
        string $userUuid,
    ): string {
        $canonicalUserUuid = trim($userUuid);

        if ($canonicalUserUuid === '') {
            throw new InvalidArgumentException(
                'Identity Credential requires a non-empty User identifier.',
            );
        }

        return $this->encryptPayload([
            'credential_type' => self::CREDENTIAL_TYPE_IDENTITY,
            'user_id' => $canonicalUserUuid,
            'expires_at' => $this->currentTimestamp()
                + $this->lifetimeInSeconds(),
        ]);
    }

    /**
     * Issue the existing Tenant-aware authentication credential.
     *
     * This method intentionally preserves the existing payload contract during
     * the transition to explicit typed Membership Credentials.
     *
     * @param  array<string, mixed>  $customClaims
     *
     * @throws JsonException
     */
    public function issueToken(
        string $userUuid,
        string $tenantUuid,
        array $customClaims = [],
    ): string {
        /*
         * Core claims are placed last so callers cannot override canonical
         * identity/Tenant/expiration values through custom claims.
         */
        $payload = array_merge(
            $customClaims,
            [
                'user_id' => $userUuid,
                'tenant_id' => $tenantUuid,
                'expires_at' => $this->currentTimestamp()
                    + $this->lifetimeInSeconds(),
            ],
        );

        return $this->encryptPayload(
            $payload,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validateAndExtract(
        string $token,
    ): ?array {
        $payload = $this->extractCanonicalPayload(
            $token,
        );

        if ($payload === null) {
            return null;
        }

        $expiresAt = $payload['expires_at'];

        /*
         * A credential is invalid exactly at expires_at.
         */
        if ($this->currentTimestamp() >= $expiresAt) {
            Log::warning(
                'Expired authentication credential blocked.',
                $this->safeLogContext($payload),
            );

            return null;
        }

        /*
         * Revocation is checked only after the credential is:
         *
         * - decryptable
         * - structurally valid
         * - unexpired
         *
         * Revocation storage failure must fail closed.
         */
        try {
            if (
                $this->revocationStore->isRevoked(
                    $token,
                )
            ) {
                Log::warning(
                    'Revoked authentication credential blocked.',
                    $this->safeLogContext($payload),
                );

                return null;
            }
        } catch (Throwable $exception) {
            Log::error(
                'Authentication credential revocation validation failed.',
                array_merge(
                    $this->safeLogContext($payload),
                    [
                        'exception' => $exception::class,
                    ],
                ),
            );

            return null;
        }

        return $payload;
    }

    public function expiresAtForRevocation(
        string $token,
    ): ?int {
        $payload = $this->extractCanonicalPayload(
            $token,
        );

        return is_array($payload)
            ? $payload['expires_at']
            : null;
    }

    /**
     * Validate only the encrypted envelope and canonical structural claims.
     *
     * This intentionally does not enforce credential lifetime or revocation so
     * logout can still persist revocation metadata for structurally valid
     * credentials without using this method as an authentication decision.
     *
     * During the migration this accepts:
     *
     * 1. canonical typed Identity Credentials; and
     * 2. existing untyped Tenant-aware credentials.
     *
     * @return array<string, mixed>|null
     */
    private function extractCanonicalPayload(
        string $token,
    ): ?array {
        try {
            $decryptedPayload = Crypt::decryptString(
                $token,
            );

            $payload = json_decode(
                $decryptedPayload,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            if (! is_array($payload)) {
                $this->logMalformedCredential();

                return null;
            }

            $userId = $payload['user_id'] ?? null;
            $expiresAt = $payload['expires_at'] ?? null;

            if (
                ! is_string($userId)
                || trim($userId) === ''
                || ! is_int($expiresAt)
            ) {
                $this->logMalformedCredential();

                return null;
            }

            $credentialType = $payload['credential_type'] ?? null;

            /*
             * Existing credentials predate credential_type.
             *
             * Preserve their structural contract temporarily so current
             * Membership/Tenant authentication continues working while the
             * explicit Membership Credential contract is introduced later.
             */
            if ($credentialType === null) {
                return $this->validateLegacyTenantPayload(
                    $payload,
                );
            }

            /*
             * B2 introduces only canonical Identity Credentials.
             *
             * Other explicit credential types remain fail-closed until their
             * own contract is implemented in a subsequent TDD phase.
             */
            if (
                ! is_string($credentialType)
                || $credentialType !== self::CREDENTIAL_TYPE_IDENTITY
            ) {
                Log::warning(
                    'Unsupported authentication credential type blocked.',
                    [
                        'user_id' => $userId,
                    ],
                );

                return null;
            }

            if (! $this->isCanonicalIdentityPayload($payload)) {
                Log::warning(
                    'Malformed Identity Credential payload blocked.',
                    [
                        'user_id' => $userId,
                    ],
                );

                return null;
            }

            return $payload;
        } catch (Throwable $exception) {
            /*
             * Never log the raw token or decrypted payload.
             */
            Log::warning(
                'Tampered or invalid authentication credential blocked.',
                [
                    'exception' => $exception::class,
                ],
            );

            return null;
        }
    }

    /**
     * Preserve the current Tenant-aware payload contract during migration.
     *
     * @param  array<string, mixed>  $payload
     *
     * @return array<string, mixed>|null
     */
    private function validateLegacyTenantPayload(
        array $payload,
    ): ?array {
        $tenantId = $payload['tenant_id'] ?? null;

        if (
            ! is_string($tenantId)
            || trim($tenantId) === ''
        ) {
            $this->logMalformedCredential();

            return null;
        }

        return $payload;
    }

    /**
     * Identity Credentials deliberately have an exact claim surface.
     *
     * Extra claims are rejected because Tenant/Membership/authorization state
     * must never be smuggled into global Identity Context.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isCanonicalIdentityPayload(
        array $payload,
    ): bool {
        if (count($payload) !== 3) {
            return false;
        }

        return array_key_exists(
            'credential_type',
            $payload,
        )
            && array_key_exists(
                'user_id',
                $payload,
            )
            && array_key_exists(
                'expires_at',
                $payload,
            );
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @return array<string, mixed>
     */
    private function safeLogContext(
        array $payload,
    ): array {
        $context = [
            'user_id' => $payload['user_id'],
            'credential_type' => $payload['credential_type']
                ?? 'legacy_tenant',
        ];

        $tenantId = $payload['tenant_id'] ?? null;

        if (
            is_string($tenantId)
            && trim($tenantId) !== ''
        ) {
            $context['tenant_id'] = $tenantId;
        }

        return $context;
    }

    private function logMalformedCredential(): void
    {
        Log::warning(
            'Malformed authentication credential payload blocked.',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    private function encryptPayload(
        array $payload,
    ): string {
        $encodedPayload = json_encode(
            $payload,
            JSON_THROW_ON_ERROR,
        );

        return Crypt::encryptString(
            $encodedPayload,
        );
    }

    private function currentTimestamp(): int
    {
        return Carbon::now()->timestamp;
    }
}
