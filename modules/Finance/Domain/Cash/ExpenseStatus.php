<?php

namespace Modules\Finance\Domain\Cash;

/**
 * Status pengajuan pengeluaran (PRD-004 §4).
 */
enum ExpenseStatus: string
{
    case Requested = 'DIAJUKAN';
    case Approved = 'DISETUJUI';
    case Rejected = 'DITOLAK';
    case Cancelled = 'DIBATALKAN';
    case Realized = 'DIREALISASI';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Menunggu persetujuan',
            self::Approved => 'Disetujui, belum direalisasi',
            self::Rejected => 'Ditolak',
            self::Cancelled => 'Dibatalkan',
            self::Realized => 'Selesai (direalisasi)',
        };
    }
}
