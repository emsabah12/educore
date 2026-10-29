<?php

namespace Modules\Finance\Domain\Payment;

/**
 * Cara bayar yang dicatat petugas (PRD-003 OD-FN-03). Payment gateway menyusul.
 */
enum PaymentMethod: string
{
    case Cash = 'TUNAI';
    case Transfer = 'TRANSFER';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Transfer => 'Transfer bank',
        };
    }
}
