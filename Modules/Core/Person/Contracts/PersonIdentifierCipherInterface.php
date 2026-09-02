<?php

declare(strict_types=1);

namespace Modules\Core\Person\Contracts;

/**
 * Kontrak kriptografi untuk person_identifiers (GAP-023 / HR-014-AC-010).
 *
 * Implementasi WAJIB:
 * - encrypt(): menghasilkan ciphertext yang tidak pernah mengandung
 *   substring dari $rawValue;
 * - fingerprint(): deterministik (nilai sama -> fingerprint sama),
 *   64 karakter hex, dan bersifat satu arah (tidak bisa dibalik ke
 *   $rawValue asli) — dipakai HANYA untuk exact-match/duplicate
 *   detection, bukan untuk merekonstruksi nilai.
 */
interface PersonIdentifierCipherInterface
{
    public function encrypt(string $rawValue): string;

    public function decrypt(string $encryptedValue): string;

    /**
     * @return string 64 karakter hex (HMAC-SHA256).
     */
    public function fingerprint(string $rawValue): string;
}
