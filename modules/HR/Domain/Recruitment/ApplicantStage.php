<?php

namespace Modules\HR\Domain\Recruitment;

/**
 * Tahap pelamar, tetap untuk semua lowongan (OD-HR-20): Seleksi berkas → Tes/wawancara → Diterima / Tidak lolos.
 */
enum ApplicantStage: string
{
    case Screening = 'BERKAS';
    case Interview = 'TES';
    case Accepted = 'DITERIMA';
    case Rejected = 'TIDAK_LOLOS';

    public function label(): string
    {
        return match ($this) {
            self::Screening => 'Seleksi berkas',
            self::Interview => 'Tes/wawancara',
            self::Accepted => 'Diterima',
            self::Rejected => 'Tidak lolos',
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Accepted || $this === self::Rejected;
    }
}
