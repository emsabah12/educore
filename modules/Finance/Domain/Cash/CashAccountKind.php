<?php

namespace Modules\Finance\Domain\Cash;

/**
 * Jenis rekening kas (PRD-004 §3): Kas tunai (otomatis, satu per kas) atau rekening bank.
 */
enum CashAccountKind: string
{
    case Cash = 'TUNAI';
    case Bank = 'BANK';
}
