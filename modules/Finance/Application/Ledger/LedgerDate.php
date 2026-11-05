<?php

namespace Modules\Finance\Application\Ledger;

/**
 * Tanggal panjang berbahasa Indonesia untuk uraian & pesan pembukuan: "2026-11-01" → "1 November 2026".
 */
final class LedgerDate
{
    private const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public static function long(string $date): string
    {
        [$year, $month, $day] = array_map('intval', explode('-', substr($date, 0, 10)) + [0, 1, 1]);

        return $day.' '.(self::MONTHS[$month - 1] ?? '').' '.$year;
    }
}
