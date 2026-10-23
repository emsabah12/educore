<?php

namespace Modules\Academic\Domain\Teaching;

/**
 * Alasan guru pengampu tidak mengajar (Digantikan / Kosong), PRD-002 §20.1.
 */
enum AbsenceReason: string
{
    case Leave = 'CUTI';
    case Sick = 'SAKIT';
    case Permit = 'IZIN';
    case Duty = 'DINAS_LUAR';
    case Absent = 'ALPA';
    case Other = 'LAINNYA';

    public function label(): string
    {
        return match ($this) {
            self::Leave => 'Cuti',
            self::Sick => 'Sakit',
            self::Permit => 'Izin',
            self::Duty => 'Dinas luar',
            self::Absent => 'Alpa',
            self::Other => 'Lainnya',
        };
    }
}
