<?php

namespace Modules\HR\Domain\Leave;

/**
 * Status pengajuan cuti (PRD-001 §13.3–§13.5).
 */
enum LeaveStatus: string
{
    case Pending = 'MENUNGGU';
    case Approved = 'DISETUJUI';
    case Rejected = 'DITOLAK';
    /** Ditarik pengaju sebelum diputuskan. */
    case Withdrawn = 'DITARIK';
    /** Cuti yang sudah disetujui dibatalkan Admin SDM (HR-3d); saldo kembali. */
    case Cancelled = 'DIBATALKAN';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::Withdrawn => 'Ditarik',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /** Memakai jatah / tanggal: menunggu atau sudah disetujui. */
    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }
}
