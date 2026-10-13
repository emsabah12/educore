<?php

namespace Modules\Core\Application\Identity;

/**
 * Kata sandi sementara 12 karakter untuk diserahkan admin secara langsung (PRD-000 §4.7, OD-20).
 *
 * - Memakai random_int() (acak kriptografis), bukan rand()/mt_rand().
 * - Selalu memuat huruf besar, huruf kecil, angka, dan simbol, sehingga lolos aturan kata sandi
 *   production (Password::defaults()).
 * - Karakter yang mudah tertukar saat dibaca/didiktekan (0/O, 1/l/I) tidak dipakai.
 */
final class TemporaryPassword
{
    public const LENGTH = 12;

    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const LOWER = 'abcdefghijkmnpqrstuvwxyz';

    private const DIGITS = '23456789';

    private const SYMBOLS = '!@#$%*?-';

    public static function generate(): string
    {
        $all = self::UPPER.self::LOWER.self::DIGITS.self::SYMBOLS;

        // Satu dari tiap golongan, sisanya dari gabungan semua golongan.
        $characters = [
            self::pick(self::UPPER),
            self::pick(self::LOWER),
            self::pick(self::DIGITS),
            self::pick(self::SYMBOLS),
        ];

        while (count($characters) < self::LENGTH) {
            $characters[] = self::pick($all);
        }

        // Acak urutan (Fisher–Yates dengan random_int) agar posisi golongan tidak bisa ditebak.
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    private static function pick(string $pool): string
    {
        return $pool[random_int(0, strlen($pool) - 1)];
    }
}
