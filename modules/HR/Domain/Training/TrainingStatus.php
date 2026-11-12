<?php

namespace Modules\HR\Domain\Training;

/**
 * Status riwayat pelatihan (PRD-001 §19.1): pengajuan pegawai menunggu verifikasi Admin SDM (OD-HR-16).
 */
enum TrainingStatus: string
{
    case Pending = 'MENUNGGU';
    case Accepted = 'DITERIMA';
    case Rejected = 'DITOLAK';
    case Cancelled = 'DIBATALKAN';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu verifikasi',
            self::Accepted => 'Diterima',
            self::Rejected => 'Ditolak',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
