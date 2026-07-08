<?php

declare(strict_types=1);

namespace Modules\Core\Support\Uuid; // SINKRONISASI: Menambahkan \Uuid sesuai folder fisik Anda

use Illuminate\Support\Str;

final class UuidV7
{
    /**
     * Generate a cryptographically secure time-ordered UUID v7 string.
     *
     * @return string Normalized lowercase UUID v7 string
     */
    public static function generate(): string
    {
        // Memanfaatkan engine internal Str::uuid7() milik Laravel/Symfony
        return strtolower((string) Str::uuid7());
    }

    /**
     * Validate whether a given string is a valid UUID v7 format.
     *
     * @param string $uuid The UUID string to validate
     * @return bool True if valid UUID v7, false otherwise
     */
    public static function validate(string $uuid): bool
    {
        $uuid = trim($uuid);

        if ($uuid === '') {
            return false;
        }

        // Regex RFC 9562 standar untuk UUID v7 (Memiliki penanda karakter '7' pada posisi versi)
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

        return preg_match($pattern, $uuid) === 1;
    }
}