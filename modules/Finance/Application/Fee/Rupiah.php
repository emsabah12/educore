<?php

namespace Modules\Finance\Application\Fee;

use Modules\Finance\Domain\Fee\Exceptions\FinanceException;

/**
 * Rupiah bulat dari isian form (PRD-003 §1 prinsip 3): "150.000", "Rp 150000", atau angka.
 */
final class Rupiah
{
    /**
     * @throws FinanceException isian bukan rupiah bulat di rentang $min..$max
     */
    public static function parse(mixed $value, string $field, string $label, int $min, int $max): int
    {
        if (is_int($value)) {
            $digits = (string) $value;
        } elseif (is_string($value)) {
            $digits = (string) preg_replace('/^\s*rp\.?\s*/i', '', $value);
            $digits = str_replace(['.', ' '], '', trim($digits));
        } else {
            $digits = '';
        }

        if (preg_match('/^\d{1,12}$/', $digits) !== 1 || (int) $digits < $min || (int) $digits > $max) {
            throw FinanceException::make($field, ucfirst($label).' harus rupiah bulat '.self::format($min).' s.d. '.self::format($max).' (tanpa koma).');
        }

        return (int) $digits;
    }

    public static function format(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}
