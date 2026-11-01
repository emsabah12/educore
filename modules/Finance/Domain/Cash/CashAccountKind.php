<?php

namespace Modules\Finance\Domain\Cash;

/**
 * Jenis rekening kas (PRD-004 §3): Kas tunai (otomatis, satu per kas) atau rekening bank. TITIPAN = "Titipan tabungan"
 * (PRD-005 §3.1): uang tabungan siswa, satu per lembaga, otomatis, tidak dihitung dalam saldo operasional dan tidak bisa
 * dipakai untuk pemasukan, pengeluaran, atau mutasi manual.
 */
enum CashAccountKind: string
{
    case Cash = 'TUNAI';
    case Bank = 'BANK';
    case Custody = 'TITIPAN';
}
