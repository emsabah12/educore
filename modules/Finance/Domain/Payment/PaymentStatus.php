<?php

namespace Modules\Finance\Domain\Payment;

/**
 * Status pembayaran (PRD-003 §4): sah atau dibatalkan (tetap tersimpan).
 */
enum PaymentStatus: string
{
    case Valid = 'SAH';
    case Void = 'BATAL';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Sah',
            self::Void => 'Batal',
        };
    }
}
