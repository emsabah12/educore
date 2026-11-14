<?php

namespace Modules\Dormitory\Application\Facility;

use Modules\Dormitory\Domain\Facility\Exceptions\FacilityException;

/**
 * Kode asrama/gedung/kamar/bed/loker (PRD-008 §4.1): huruf besar, angka, dan `-`, maks. 30 karakter. Spasi diubah
 * menjadi `-` (mis. "a 101" → "A-101") supaya petugas tidak perlu hafal formatnya. Sama dengan CHECK di database.
 */
final class FacilityCode
{
    public const MAX_LENGTH = 30;

    private const PATTERN = '/^[A-Z0-9]+(-[A-Z0-9]+)*$/';

    public static function clean(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $code = mb_strtoupper(trim($value));
        $code = (string) preg_replace('/\s+/u', '-', $code);
        $code = (string) preg_replace('/-{2,}/', '-', $code);

        return trim($code, '-');
    }

    /**
     * @throws FacilityException
     */
    public static function assertValid(string $code, string $field = 'code'): void
    {
        if ($code === '') {
            throw FacilityException::make($field, 'Kode wajib diisi.');
        }

        if (mb_strlen($code) > self::MAX_LENGTH) {
            throw FacilityException::make($field, 'Kode paling panjang '.self::MAX_LENGTH.' karakter.');
        }

        if (preg_match(self::PATTERN, $code) !== 1) {
            throw FacilityException::make($field, 'Kode hanya boleh berisi huruf, angka, dan tanda "-".');
        }
    }

    /**
     * Nama dirapikan: spasi ganda dihapus. Kosong tetap kosong (pemanggil yang memutuskan wajib atau tidak).
     */
    public static function cleanName(mixed $value): string
    {
        return is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
    }
}
