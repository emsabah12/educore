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
}
