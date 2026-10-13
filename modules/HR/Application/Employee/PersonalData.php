<?php

namespace Modules\HR\Application\Employee;

use Illuminate\Support\Str;

/**
 * Perapian isian data pegawai sebelum divalidasi dan disimpan (PRD-001 §4.3, §4.5).
 */
final class PersonalData
{
    /** Pola yang sama dengan CHECK di database (persons_phone_format_check). */
    public const PHONE_PATTERN = '/^\+?[0-9]{8,19}$/';

    /** Pola yang sama dengan CHECK di database (hr_employees_number_format_check). */
    public const EMPLOYEE_NUMBER_PATTERN = '/^[A-Z0-9][A-Z0-9.\/-]{0,29}$/';

    public const NUPTK_PATTERN = '/^[0-9]{16}$/';

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
     * Nomor HP disimpan tanpa spasi, titik, tanda hubung, dan tanda kurung: "0812-3456 789" → "08123456789".
     */
    public static function phone(mixed $value): ?string
    {
        $value = self::text($value);

        return $value === null ? null : (string) preg_replace('/[\s.\-()]/', '', $value);
    }

    /**
     * Nomor induk yayasan disimpan huruf besar tanpa spasi di tepi.
     */
    public static function employeeNumber(mixed $value): ?string
    {
        $value = self::text($value);

        return $value === null ? null : Str::upper($value);
    }

    /**
     * NUPTK hanya angka; spasi di dalamnya dibuang ("1234 5678 9012 3456").
     */
    public static function nuptk(mixed $value): ?string
    {
        $value = self::text($value);

        return $value === null ? null : (string) preg_replace('/\s/', '', $value);
    }
}
