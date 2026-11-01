<?php

namespace Modules\Finance\Domain\Savings;

/**
 * Jenis transaksi tabungan siswa (PRD-005 §3). BAYAR_TAGIHAN = saldo dipakai membayar tagihan di kasir (TB-3).
 */
enum SavingsEntryKind: string
{
    case Deposit = 'SETOR';
    case Withdrawal = 'TARIK';
    case InvoicePayment = 'BAYAR_TAGIHAN';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Setoran',
            self::Withdrawal => 'Penarikan',
            self::InvoicePayment => 'Bayar tagihan',
        };
    }
}
