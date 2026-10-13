<?php

namespace Modules\HR\Domain\Employee;

/**
 * Alasan periode kepegawaian ditutup (PRD-001 §4.3).
 * PERUBAHAN_STATUS diisi sistem saat ganti jenis/status kepegawaian, bukan dipilih pengguna.
 */
enum EndReason: string
{
    case Resigned = 'MENGUNDURKAN_DIRI';
    case ContractEnded = 'KONTRAK_SELESAI';
    case Retired = 'PENSIUN';
    case Dismissed = 'DIBERHENTIKAN';
    case Deceased = 'MENINGGAL';
    case Other = 'LAINNYA';
    case StatusChanged = 'PERUBAHAN_STATUS';

    public function label(): string
    {
        return match ($this) {
            self::Resigned => 'Mengundurkan diri',
            self::ContractEnded => 'Kontrak selesai',
            self::Retired => 'Pensiun',
            self::Dismissed => 'Diberhentikan',
            self::Deceased => 'Meninggal dunia',
            self::Other => 'Lainnya',
            self::StatusChanged => 'Perubahan status kepegawaian',
        };
    }
}
