<?php

namespace Modules\Finance\Domain\Cash;

/**
 * Asal transaksi kas (PRD-004 §3). Hanya MANUAL yang dibatalkan dari buku kas; yang lain dibatalkan dari asalnya.
 */
enum CashEntrySource: string
{
    case Manual = 'MANUAL';
    case Payment = 'PEMBAYARAN';
    case Expense = 'PENGELUARAN';
    case Transfer = 'MUTASI';
    /** Setoran/penarikan tabungan siswa (PRD-005 §3.1) di rekening Titipan tabungan; dibatalkan dari tabungan. */
    case Savings = 'TABUNGAN';
}
