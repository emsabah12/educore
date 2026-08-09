<?php

declare(strict_types=1);

namespace Modules\Auth\Token\Persistence;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Auth\Token\Contracts\TokenRevocationStoreInterface;

final class DatabaseTokenRevocationStore implements TokenRevocationStoreInterface
{
    private const TABLE = 'auth_token_revocations';

    private const HASH_ALGORITHM = 'sha256';

    public function revoke(
        string $token,
        int $expiresAt,
    ): void {
        $token = trim($token);

        if ($token === '') {
            throw new InvalidArgumentException(
                'Bearer token is required for revocation.',
            );
        }

        /*
         * Token yang sudah expired tidak membutuhkan revocation
         * persistence karena token tersebut sudah invalid secara
         * cryptographic authentication lifecycle.
         */
        if ($expiresAt <= now()->timestamp) {
            return;
        }

        $now = now();

        DB::table(self::TABLE)->updateOrInsert(
            [
                'token_hash' => $this->fingerprint(
                    $token,
                ),
            ],
            [
                'expires_at' => $expiresAt,
                'revoked_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function isRevoked(
        string $token,
    ): bool {
        $token = trim($token);

        if ($token === '') {
            return false;
        }

        return DB::table(self::TABLE)
            ->where(
                'token_hash',
                $this->fingerprint($token),
            )
            ->where(
                'expires_at',
                '>',
                now()->timestamp,
            )
            ->exists();
    }

    private function fingerprint(
        string $token,
    ): string {
        return hash(
            self::HASH_ALGORITHM,
            $token,
        );
    }
}
