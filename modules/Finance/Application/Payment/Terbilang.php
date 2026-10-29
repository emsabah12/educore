<?php

namespace Modules\Finance\Application\Payment;

/**
 * Angka rupiah → kata dalam Bahasa Indonesia untuk kuitansi, mis. 150000 → "seratus lima puluh ribu rupiah".
 */
final class Terbilang
{
    private const WORDS = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

    public static function rupiah(int $amount): string
    {
        return ($amount === 0 ? 'nol' : self::words($amount)).' rupiah';
    }

    private static function words(int $number): string
    {
        return trim((string) preg_replace('/\s+/', ' ', self::spell($number)));
    }

    private static function spell(int $n): string
    {
        return match (true) {
            $n < 12 => self::WORDS[$n],
            $n < 20 => self::spell($n - 10).' belas',
            $n < 100 => self::spell(intdiv($n, 10)).' puluh '.self::spell($n % 10),
            $n < 200 => 'seratus '.self::spell($n - 100),
            $n < 1000 => self::spell(intdiv($n, 100)).' ratus '.self::spell($n % 100),
            $n < 2000 => 'seribu '.self::spell($n - 1000),
            $n < 1_000_000 => self::spell(intdiv($n, 1000)).' ribu '.self::spell($n % 1000),
            $n < 1_000_000_000 => self::spell(intdiv($n, 1_000_000)).' juta '.self::spell($n % 1_000_000),
            $n < 1_000_000_000_000 => self::spell(intdiv($n, 1_000_000_000)).' miliar '.self::spell($n % 1_000_000_000),
            default => self::spell(intdiv($n, 1_000_000_000_000)).' triliun '.self::spell($n % 1_000_000_000_000),
        };
    }
}
