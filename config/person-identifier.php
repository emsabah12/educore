<?php

declare(strict_types=1);

return [
    /*
     * Kunci HMAC-SHA256 untuk menghasilkan value_fingerprint pada
     * person_identifiers (GAP-023 / HR-014-AC-010).
     *
     * Sengaja terpisah dari APP_KEY (yang dipakai untuk AES encryption
     * ciphertext lewat Crypt facade) — memakai key yang sama untuk dua
     * primitive kriptografi berbeda (enkripsi vs HMAC) adalah hygiene
     * yang buruk. Tidak ada default yang di-commit; wajib diisi
     * eksplisit di .env sebelum fitur yang menyimpan government/legal
     * identifier (NIK, paspor, dst.) dipakai.
     */
    'fingerprint_key' => env('PERSON_IDENTIFIER_FINGERPRINT_KEY'),
];
