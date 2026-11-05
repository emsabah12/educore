<?php

namespace Modules\Finance\Domain\Ledger;

/**
 * Asal jurnal (PRD-007 §4–§6). Jurnal otomatis: TAGIHAN, KAS, MUTASI, SALDO_REKENING. SALDO_AWAL & MANUAL dicatat
 * akuntan (PB-2).
 */
enum JournalSource: string
{
    case Invoice = 'TAGIHAN';
    case CashEntry = 'KAS';
    case Transfer = 'MUTASI';
    case AccountOpening = 'SALDO_REKENING';
    case Opening = 'SALDO_AWAL';
    case Manual = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Tagihan',
            self::CashEntry => 'Transaksi kas',
            self::Transfer => 'Mutasi',
            self::AccountOpening => 'Saldo awal rekening',
            self::Opening => 'Saldo awal',
            self::Manual => 'Jurnal manual',
        };
    }

    /**
     * @return list<self>
     */
    public static function automatic(): array
    {
        return [self::Invoice, self::CashEntry, self::Transfer, self::AccountOpening];
    }
}
