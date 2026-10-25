<?php

namespace Modules\HR\Domain\Payroll;

/**
 * Jenis komponen gaji (PRD-001 §16.1): menambah (pendapatan) atau mengurangi (potongan) gaji.
 */
enum PayComponentKind: string
{
    case Earning = 'PENDAPATAN';
    case Deduction = 'POTONGAN';

    public function label(): string
    {
        return match ($this) {
            self::Earning => 'Pendapatan',
            self::Deduction => 'Potongan',
        };
    }
}
