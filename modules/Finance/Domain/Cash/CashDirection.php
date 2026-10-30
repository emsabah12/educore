<?php

namespace Modules\Finance\Domain\Cash;

/**
 * Arah transaksi kas (PRD-004 §3).
 */
enum CashDirection: string
{
    case In = 'MASUK';
    case Out = 'KELUAR';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Pemasukan',
            self::Out => 'Pengeluaran',
        };
    }
}
