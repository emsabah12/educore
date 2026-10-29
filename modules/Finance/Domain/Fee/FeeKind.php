<?php

namespace Modules\Finance\Domain\Fee;

/**
 * Sifat jenis biaya (PRD-003 §4): ditagih tiap bulan (mis. SPP) atau sekali (mis. daftar ulang, seragam).
 */
enum FeeKind: string
{
    case Monthly = 'BULANAN';
    case Once = 'SEKALI';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Bulanan',
            self::Once => 'Sekali bayar',
        };
    }
}
