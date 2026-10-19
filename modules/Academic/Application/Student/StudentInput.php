<?php

namespace Modules\Academic\Application\Student;

use Illuminate\Support\Str;

/**
 * Perapian isian data siswa & orang tua/wali sebelum divalidasi dan disimpan (PRD-002 §4.2, §4.5).
 * Pola sama dengan data pegawai (PRD-001 §4.5) dan CHECK di database.
 */
final class StudentInput
{
    /** Sama dengan persons_phone_format_check. */
    public const PHONE_PATTERN = '/^\+?[0-9]{8,19}$/';

    /** Sama dengan ac_students_nisn_format_check. */
    public const NISN_PATTERN = '/^[0-9]{10}$/';

    /** Sama dengan ac_enrollments_number_format_check. */
    public const STUDENT_NUMBER_PATTERN = '/^[A-Z0-9][A-Z0-9.\/-]{0,29}$/';

    public const NAME_MAX_LENGTH = 200;

    public const MAX_GUARDIANS = 4;

    /**
     * Pangkas spasi; string kosong menjadi null.
     */
    public static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Nama dengan spasi ganda dirapikan: "  Ahmad   Fauzi " → "Ahmad Fauzi".
     */
    public static function name(mixed $value): ?string
    {
        $value = self::text($value);

        return $value === null ? null : (string) preg_replace('/\s+/u', ' ', $value);
    }

    /**
     * Nomor HP tanpa spasi, titik, tanda hubung, dan tanda kurung: "0812-3456 789" → "08123456789".
     */
    public static function phone(mixed $value): ?string
    {
        $value = self::text($value);

        return $value === null ? null : (string) preg_replace('/[\s.\-()]/', '', $value);
    }

    /**
     * NISN hanya angka; spasi di dalamnya dibuang.
     */
    public static function nisn(mixed $value): ?string
    {
        $value = self::text($value);

        return $value === null ? null : (string) preg_replace('/\s/', '', $value);
    }

    /**
     * Nomor induk siswa disimpan huruf besar.
     */
    public static function studentNumber(mixed $value): ?string
    {
        $value = self::text($value);

        return $value === null ? null : Str::upper($value);
    }

    /**
     * Bentuk pembanding nama: huruf kecil, spasi ganda dirapikan (sama dengan SQL di DuplicateCandidates).
     */
    public static function comparableName(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }

    /**
     * Tanggal kalender yang sah dengan format YYYY-MM-DD (perbandingan string aman untuk format ini).
     */
    public static function isDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
