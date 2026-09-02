<?php

declare(strict_types=1);

namespace Modules\Core\Person\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Modules\Core\Person\Contracts\PersonIdentifierCipherInterface;
use RuntimeException;

final class PersonIdentifierCipher implements PersonIdentifierCipherInterface
{
    /**
     * @throws RuntimeException Jika $rawValue kosong.
     */
    public function encrypt(string $rawValue): string
    {
        $rawValue = trim($rawValue);

        if ($rawValue === '') {
            throw new RuntimeException(
                'Person identifier value cannot be empty.',
            );
        }

        // Crypt::encryptString memakai APP_KEY (AES-256-CBC dengan
        // random IV per panggilan) — pola yang sama seperti yang
        // sudah dipakai DeterministicTokenManager untuk payload token.
        return Crypt::encryptString($rawValue);
    }

    /**
     * @throws RuntimeException Jika ciphertext tidak valid/rusak.
     */
    public function decrypt(string $encryptedValue): string
    {
        try {
            return Crypt::decryptString($encryptedValue);
        } catch (DecryptException $e) {
            throw new RuntimeException(
                'Failed to decrypt person identifier value.',
                previous: $e,
            );
        }
    }

    public function fingerprint(string $rawValue): string
    {
        $rawValue = trim($rawValue);

        if ($rawValue === '') {
            throw new RuntimeException(
                'Person identifier value cannot be empty.',
            );
        }

        return hash_hmac(
            'sha256',
            $rawValue,
            $this->fingerprintKey(),
        );
    }

    /**
     * @throws RuntimeException Jika PERSON_IDENTIFIER_FINGERPRINT_KEY
     *                           belum dikonfigurasi. Sengaja fail-closed
     *                           — tidak pernah fallback diam-diam ke
     *                           APP_KEY atau nilai kosong.
     */
    private function fingerprintKey(): string
    {
        $key = config('person-identifier.fingerprint_key');

        if (! is_string($key) || trim($key) === '') {
            throw new RuntimeException(
                'PERSON_IDENTIFIER_FINGERPRINT_KEY is not configured. '
                    . 'Set it in .env before storing government/legal '
                    . 'identifiers (NIK, passport, etc.).',
            );
        }

        return $key;
    }
}
