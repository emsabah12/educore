<?php

namespace Modules\HR\Domain\Recruitment;

/**
 * Aksi di riwayat seleksi pelamar (PRD-001 §20.1).
 */
enum StepAction: string
{
    case PassScreening = 'LOLOS_BERKAS';
    case Accept = 'DITERIMA';
    case Reject = 'TIDAK_LOLOS';
    case Reopen = 'KEMBALI';
    case Note = 'CATATAN';

    public function label(): string
    {
        return match ($this) {
            self::PassScreening => 'Lolos seleksi berkas',
            self::Accept => 'Diterima',
            self::Reject => 'Tidak lolos',
            self::Reopen => 'Dikembalikan ke Tes/wawancara',
            self::Note => 'Catatan',
        };
    }
}
