<?php

namespace Modules\HR\Domain\Payroll;

/**
 * Cara hitung komponen gaji (PRD-001 §16.1): nominal tetap per bulan, atau nominal × jumlah hari
 * hadir (Hadir + Dinas luar) di rekap presensi bulan itu (HR-4c).
 */
enum PayComponentBasis: string
{
    case Monthly = 'TETAP';
    case PerPresentDay = 'PER_HARI_HADIR';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Tetap per bulan',
            self::PerPresentDay => 'Per hari hadir',
        };
    }
}
