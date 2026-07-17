<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Modules\Core\Contracts\Auth\TokenManagerInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Exception;

final class DeterministicTokenManager implements TokenManagerInterface
{
    /**
     * Masa berlaku token dalam hitungan detik (2 Jam = 7200 detik).
     */
    private const TOKEN_LIFETIME = 7200;

    /**
     * Menerbitkan token terenkripsi dengan mendukung klaim kustom tambahan.
     */
    public function issueToken(string $userUuid, string $tenantUuid, array $customClaims = []): string
    {
        // Menggabungkan klaim inti multi-tenant dengan klaim kustom dari argumen ketiga
        $payload = array_merge($customClaims, [
            'user_id'    => $userUuid,
            'tenant_id'  => $tenantUuid,
            'expires_at' => time() + self::TOKEN_LIFETIME,
        ]);

        // Enkripsi payload secara simetris menggunakan APP_KEY Laravel
        return Crypt::encryptString(json_encode($payload));
    }

    /**
     * Mengurai, mendekripsi, dan memvalidasi integritas masa aktif token sesuai kontrak core.
     */
    public function validateAndExtract(string $token): ?array
    {
        try {
            // Dekripsi string token mentah
            $decryptedRaw = Crypt::decryptString($token);
            $payload = json_decode($decryptedRaw, true);

            // Validasi format struktur payload internal minimum
            if (!isset($payload['user_id'], $payload['tenant_id'], $payload['expires_at'])) {
                return null;
            }

            // Defensive Guard: Cek apakah masa berlaku token sudah kadaluarsa
            if (time() > $payload['expires_at']) {
                Log::warning('Expired authentication token blocked.', ['user_id' => $payload['user_id']]);
                return null;
            }

            // Mengembalikan seluruh payload terurai (termasuk klaim kustom jika ada)
            return $payload;
        } catch (Exception $e) {
            // Catat kegagalan jika ada upaya manipulasi token biner
            Log::error('Tampered or invalid token decryption attempt blocked.', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
