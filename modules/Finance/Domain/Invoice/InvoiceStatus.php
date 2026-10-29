<?php

namespace Modules\Finance\Domain\Invoice;

/**
 * Status tagihan siswa (PRD-003 §4).
 */
enum InvoiceStatus: string
{
    case Open = 'TERBUKA';
    case Paid = 'LUNAS';
    case Void = 'BATAL';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Belum lunas',
            self::Paid => 'Lunas',
            self::Void => 'Batal',
        };
    }
}
