<?php

namespace Modules\Finance\Domain\Payment;

/**
 * Cara bayar yang dicatat petugas (PRD-003 OD-FN-03). TABUNGAN = dari saldo tabungan siswa (PRD-005 §4.3, TB-3); hanya
 * untuk kuitansi, bukan cara setor/tarik tabungan. ONLINE = dibuat otomatis dari pembayaran Midtrans (PRD-006 MG-2), tidak
 * bisa dipilih petugas.
 */
enum PaymentMethod: string
{
    /**
     * Cara bayar yang bisa dipilih petugas di Kasir.
     *
     * @return list<self>
     */
    public static function cashier(): array
    {
        return [self::Cash, self::Transfer, self::Savings];
    }

    case Cash = 'TUNAI';
    case Transfer = 'TRANSFER';
    case Savings = 'TABUNGAN';
    case Online = 'ONLINE';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Transfer => 'Transfer bank',
            self::Savings => 'Tabungan siswa',
            self::Online => 'Pembayaran online',
        };
    }
}
