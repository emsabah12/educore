<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use JsonException;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Throwable;

final class DeterministicTokenManager implements TokenManagerInterface
{
    /**
     * Masa berlaku token dalam detik.
     *
     * Dua jam = 7.200 detik.
     */
    private const TOKEN_LIFETIME = 7200;

    public function lifetimeInSeconds(): int
    {
        return self::TOKEN_LIFETIME;
    }

    /**
     * @param array<string, mixed> $customClaims
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

        return Crypt::encryptString($encodedPayload);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validateAndExtract(string $token): ?array
    {
        try {
            $decryptedPayload = Crypt::decryptString($token);

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

            return $payload;
        } catch (Throwable $exception) {
            /*
             * Jangan mencatat token mentah atau decrypted payload.
             * Keduanya merupakan data sensitif.
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
