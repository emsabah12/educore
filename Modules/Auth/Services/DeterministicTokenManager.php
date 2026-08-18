<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use JsonException;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Auth\Token\Contracts\TokenRevocationStoreInterface;
use Throwable;

final class DeterministicTokenManager implements TokenManagerInterface
{
    /**
     * Masa berlaku token dalam detik.
     *
     * Dua jam = 7.200 detik.
     */
    private const TOKEN_LIFETIME = 7200;

    public function __construct(
        private readonly TokenRevocationStoreInterface $revocationStore,
    ) {}

    public function lifetimeInSeconds(): int
    {
        return self::TOKEN_LIFETIME;
    }

    /**
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
         * Core claims ditempatkan terakhir agar tidak dapat ditimpa
         * melalui custom claims.
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

        $encodedPayload = json_encode(
            $payload,
            JSON_THROW_ON_ERROR,
        );

        return Crypt::encryptString(
            $encodedPayload,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validateAndExtract(
        string $token,
    ): ?array {
        $payload = $this->extractCanonicalPayload($token);

        if ($payload === null) {
            return null;
        }

        $userId = $payload['user_id'];
        $tenantId = $payload['tenant_id'];
        $expiresAt = $payload['expires_at'];

        /*
         * Token tidak lagi valid tepat pada detik expires_at.
         */
        if ($this->currentTimestamp() >= $expiresAt) {
            Log::warning(
                'Expired authentication token blocked.',
                [
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                ],
            );

            return null;
        }

        /*
         * Revocation diperiksa hanya setelah token terbukti:
         *
         * - decryptable
         * - structurally valid
         * - belum expired
         *
         * Database failure harus fail-closed: authentication token
         * tidak boleh dianggap valid jika revocation state tidak
         * dapat diverifikasi.
         */
        try {
            if (
                $this->revocationStore->isRevoked(
                    $token,
                )
            ) {
                Log::warning(
                    'Revoked authentication token blocked.',
                    [
                        'user_id' => $userId,
                        'tenant_id' => $tenantId,
                    ],
                );

                return null;
            }
        } catch (Throwable $exception) {
            Log::error(
                'Authentication token revocation validation failed.',
                [
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                    'exception' => $exception::class,
                ],
            );

            return null;
        }

        return $payload;
    }

    public function expiresAtForRevocation(
        string $token,
    ): ?int {
        $payload = $this->extractCanonicalPayload($token);

        return is_array($payload)
            ? $payload['expires_at']
            : null;
    }

    /**
     * Validate only the encrypted envelope and canonical core claims.
     *
     * This intentionally does not enforce token lifetime or revocation so the
     * logout lifecycle can still persist a revocation for a structurally valid
     * credential without treating this method as authentication.
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
                return null;
            }

            $userId = $payload['user_id'] ?? null;
            $tenantId = $payload['tenant_id'] ?? null;
            $expiresAt = $payload['expires_at'] ?? null;

            if (
                ! is_string($userId)
                || trim($userId) === ''
                || ! is_string($tenantId)
                || trim($tenantId) === ''
                || ! is_int($expiresAt)
            ) {
                Log::warning(
                    'Malformed authentication token payload blocked.',
                );

                return null;
            }

            return $payload;
        } catch (Throwable $exception) {
            /*
             * Jangan mencatat raw token atau decrypted payload.
             */
            Log::warning(
                'Tampered or invalid authentication token blocked.',
                [
                    'exception' => $exception::class,
                ],
            );

            return null;
        }
    }

    private function currentTimestamp(): int
    {
        return Carbon::now()->timestamp;
    }
}
