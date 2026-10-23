<?php

namespace Tests\Support;

/**
 * NIK uji yang unik dan COCOK dengan jenis kelamin & tanggal lahir (digit 7–12 = DDMMYY, tanggal +40
 * untuk perempuan), agar tidak memicu peringatan "NIK tidak cocok". Bukan NIK sungguhan.
 */
final class TestNik
{
    private static int $sequence = 0;

    public static function for(string $gender = 'L', ?string $birthDate = null): string
    {
        self::$sequence++;

        [$year, $month, $day] = $birthDate !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birthDate, $parts) === 1
            ? [(int) $parts[1], (int) $parts[2], (int) $parts[3]]
            : [1990, 1, 1];

        return '320101'
            .sprintf('%02d%02d%02d', $gender === 'P' ? $day + 40 : $day, $month, $year % 100)
            .sprintf('%04d', self::$sequence % 10000);
    }
}
