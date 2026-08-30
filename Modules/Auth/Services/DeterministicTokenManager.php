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

    private const CREDENTIAL_TYPE_MEMBERSHIP = 'membership';

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
     * @throws JsonException
     */
    public function issueIdentityToken(
        string $userUuid,
    ): string {
        $canonicalUserUuid = $this->requireIdentifier(
            $userUuid,
            'User',
        );

        return $this->encryptPayload([
            'credential_type' => self::CREDENTIAL_TYPE_IDENTITY,
            'user_id' => $canonicalUserUuid,
            'expires_at' => $this->expirationTimestamp(),
        ]);
    }

    /**
     * Issue an explicit canonical Membership Credential.
     *
     * No arbitrary claims are accepted. In particular, role and permission
     * state must remain backend-authoritative and must not be embedded into
     * the bearer credential.
     *
     * @throws JsonException
     */
    public function issueMembershipToken(
        string $userUuid,
        string $tenantUuid,
        string $membershipUuid,
    ): string {
        $canonicalUserUuid = $this->requireIdentifier(
            $userUuid,
            'User',
        );

        $canonicalTenantUuid = $this->requireIdentifier(
            $tenantUuid,
            'Tenant',
        );

        $canonicalMembershipUuid = $this->requireIdentifier(
            $membershipUuid,
            'Membership',
        );

        return $this->encryptPayload([
            'credential_type' => self::CREDENTIAL_TYPE_MEMBERSHIP,
            'user_id' => $canonicalUserUuid,
            'tenant_id' => $canonicalTenantUuid,
            'membership_id' => $canonicalMembershipUuid,
            'expires_at' => $this->expirationTimestamp(),
        ]);
    }

    /**
     * Issue the existing legacy Tenant-aware credential.
     *
     * This method intentionally preserves the old payload contract while
     * existing callers are migrated to issueMembershipToken().
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
         * User/Tenant/expiration values through custom claims.
         */
        $payload = array_merge(
            $customClaims,
            [
                'user_id' => $userUuid,
                'tenant_id' => $tenantUuid,
                'expires_at' => $this->expirationTimestamp(),
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

        if (
            $this->currentTimestamp()
            >= $payload['expires_at']
        ) {
            Log::warning(
                'Expired authentication credential blocked.',
                $this->safeLogContext($payload),
            );

            return null;
        }

        /*
         * Revocation persistence failure must fail closed.
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
     * Validate encrypted envelope and canonical structural claims only.
     *
     * During migration three credential shapes are accepted:
     *
     * 1. typed Identity Credential;
     * 2. typed Membership Credential;
     * 3. legacy untyped Tenant-aware credential.
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

            $credentialType = $payload['credential_type']
                ?? null;

            /*
             * Compatibility path for credentials issued before typed
             * credential contracts existed.
             */
            if ($credentialType === null) {
                return $this->validateLegacyTenantPayload(
                    $payload,
                );
            }

            if (! is_string($credentialType)) {
                $this->logUnsupportedCredential(
                    $userId,
                );

                return null;
            }

            if (
                $credentialType
                === self::CREDENTIAL_TYPE_IDENTITY
            ) {
                if (
                    ! $this->isCanonicalIdentityPayload(
                        $payload,
                    )
                ) {
                    Log::warning(
                        'Malformed Identity Credential payload blocked.',
                        [
                            'user_id' => $userId,
                        ],
                    );

                    return null;
                }

                return $payload;
            }

            if (
                $credentialType
                === self::CREDENTIAL_TYPE_MEMBERSHIP
            ) {
                if (
                    ! $this->isCanonicalMembershipPayload(
                        $payload,
                    )
                ) {
                    Log::warning(
                        'Malformed Membership Credential payload blocked.',
                        [
                            'user_id' => $userId,
                        ],
                    );

                    return null;
                }

                return $payload;
            }

            $this->logUnsupportedCredential(
                $userId,
            );

            return null;
        } catch (Throwable $exception) {
            /*
             * Never log raw bearer material or decrypted payloads.
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
     * Preserve the existing Tenant-aware payload contract during migration.
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
     * Identity Credentials have an exact claim surface.
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
     * Membership Credentials have an exact claim surface.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isCanonicalMembershipPayload(
        array $payload,
    ): bool {
        if (count($payload) !== 5) {
            return false;
        }

        if (
            ! array_key_exists(
                'credential_type',
                $payload,
            )
            || ! array_key_exists(
                'user_id',
                $payload,
            )
            || ! array_key_exists(
                'tenant_id',
                $payload,
            )
            || ! array_key_exists(
                'membership_id',
                $payload,
            )
            || ! array_key_exists(
                'expires_at',
                $payload,
            )
        ) {
            return false;
        }

        $tenantId = $payload['tenant_id'];
        $membershipId = $payload['membership_id'];

        return is_string($tenantId)
            && trim($tenantId) !== ''
            && is_string($membershipId)
            && trim($membershipId) !== '';
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

        $membershipId = $payload['membership_id']
            ?? null;

        if (
            is_string($membershipId)
            && trim($membershipId) !== ''
        ) {
            $context['membership_id'] = $membershipId;
        }

        return $context;
    }

    private function logMalformedCredential(): void
    {
        Log::warning(
            'Malformed authentication credential payload blocked.',
        );
    }

    private function logUnsupportedCredential(
        string $userId,
    ): void {
        Log::warning(
            'Unsupported authentication credential type blocked.',
            [
                'user_id' => $userId,
            ],
        );
    }

    private function requireIdentifier(
        string $identifier,
        string $label,
    ): string {
        $canonicalIdentifier = trim(
            $identifier,
        );

        if ($canonicalIdentifier === '') {
            throw new InvalidArgumentException(
                sprintf(
                    '%s identifier must not be empty.',
                    $label,
                ),
            );
        }

        return $canonicalIdentifier;
    }

    private function expirationTimestamp(): int
    {
        return $this->currentTimestamp()
            + $this->lifetimeInSeconds();
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    private function encryptPayload(
        array $payload,
    ): string {
        return Crypt::encryptString(
            json_encode(
                $payload,
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function currentTimestamp(): int
    {
        return Carbon::now()->timestamp;
    }
}
