<?php

namespace Modules\Core\Support\Import;

use DateInterval;
use DateTimeImmutable;

/**
 * Membaca isi sel file impor (lihat SpreadsheetReader) menjadi teks atau tanggal. Dipakai pemeriksa
 * impor modul (Academic; HR masih punya salinan sendiri di validatornya).
 */
final class ImportCell
{
    /** Rentang nomor seri tanggal Excel yang masuk akal (1900-01-01 s.d. 9999-12-31). */
    private const EXCEL_SERIAL_MAX = 2958465;

    /**
     * Isian sel menjadi teks yang sudah dipangkas; kosong = null. Angka bulat ditulis tanpa ".0".
     */
    public static function text(bool|DateTimeImmutable|float|int|string|null $raw): ?string
    {
        if (is_string($raw)) {
            $raw = trim($raw);

            return $raw === '' ? null : $raw;
        }

        if (is_int($raw)) {
            return (string) $raw;
        }

        if (is_float($raw)) {
            return floor($raw) === $raw ? sprintf('%.0f', $raw) : (string) $raw;
        }

        if ($raw instanceof DateTimeImmutable) {
            return $raw->format('Y-m-d');
        }

        if (is_bool($raw)) {
            return $raw ? 'TRUE' : 'FALSE';
        }

        return null;
    }

    public static function isEmpty(bool|DateTimeImmutable|float|int|string|null $raw): bool
    {
        return self::text($raw) === null;
    }

    public static function isNumber(bool|DateTimeImmutable|float|int|string|null $raw): bool
    {
        return is_int($raw) || is_float($raw);
    }

    /**
     * Sel tanggal Excel, nomor seri tanggal Excel, "YYYY-MM-DD", atau "DD/MM/YYYY" → "YYYY-MM-DD".
     * Kosong → null; tidak bisa dibaca → false.
     */
    public static function date(bool|DateTimeImmutable|float|int|string|null $raw): string|false|null
    {
        if (self::isEmpty($raw)) {
            return null;
        }

        if ($raw instanceof DateTimeImmutable) {
            return $raw->format('Y-m-d');
        }

        if (is_int($raw) || is_float($raw)) {
            return self::fromExcelSerial($raw) ?? false;
        }

        if (is_string($raw)) {
            return self::fromText(trim($raw)) ?? false;
        }

        return false;
    }

    private static function fromExcelSerial(float|int $serial): ?string
    {
        if ($serial < 1 || $serial > self::EXCEL_SERIAL_MAX) {
            return null;
        }

        // Excel menghitung hari sejak 1899-12-30 (termasuk "29 Februari 1900" yang keliru).
        return (new DateTimeImmutable('1899-12-30'))->add(new DateInterval('P'.(int) floor($serial).'D'))->format('Y-m-d');
    }

    private static function fromText(string $text): ?string
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $parts) === 1) {
            [$year, $month, $day] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $text, $parts) === 1) {
            [$day, $month, $year] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
        } else {
            return null;
        }

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
