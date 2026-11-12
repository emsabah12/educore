<?php

namespace Modules\HR\Domain\Training;

/**
 * Jenis riwayat pelatihan pegawai (PRD-001 §19.1).
 */
enum TrainingKind: string
{
    case Training = 'PELATIHAN';
    case Seminar = 'SEMINAR';
    case Certification = 'SERTIFIKASI';
    case Other = 'LAINNYA';

    public function label(): string
    {
        return match ($this) {
            self::Training => 'Pelatihan/Diklat',
            self::Seminar => 'Seminar/Workshop',
            self::Certification => 'Sertifikasi',
            self::Other => 'Lainnya',
        };
    }
}
