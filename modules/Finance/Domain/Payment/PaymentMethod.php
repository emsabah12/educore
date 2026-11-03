<?php

namespace Modules\Finance\Domain\Payment;

/**
 * Cara bayar yang dicatat petugas (PRD-003 OD-FN-03). TABUNGAN = dari saldo tabungan siswa (PRD-005 §4.3, TB-3); hanya
 * untuk kuitansi, bukan cara setor/tarik tabungan. Payment gateway menyusul.
 */
enum PaymentMethod: string
{
    case Cash = 'TUNAI';
    case Transfer = 'TRANSFER';
    case Savings = 'TABUNGAN';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Transfer => 'Transfer bank',
            self::Savings => 'Tabungan siswa',
        };
    }
}
