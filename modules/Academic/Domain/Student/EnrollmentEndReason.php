<?php

namespace Modules\Academic\Domain\Student;

/**
 * Alasan pendaftaran di lembaga ditutup (PRD-002 §4.2). Dipakai mulai AC-1c.
 */
enum EnrollmentEndReason: string
{
    case Graduated = 'LULUS';
    case Transferred = 'PINDAH';
    case Resigned = 'MENGUNDURKAN_DIRI';
    case Expelled = 'DIKELUARKAN';
    case Deceased = 'MENINGGAL';
    case Other = 'LAINNYA';

    public function label(): string
    {
        return match ($this) {
            self::Graduated => 'Lulus',
            self::Transferred => 'Pindah sekolah',
            self::Resigned => 'Mengundurkan diri',
            self::Expelled => 'Dikeluarkan',
            self::Deceased => 'Meninggal dunia',
            self::Other => 'Lainnya',
        };
    }
}
