<?php

namespace Modules\Finance\Domain\Cash;

/**
 * Status pengajuan pengeluaran (PRD-004 §4; MENUNGGU_YAYASAN = persetujuan kedua berjenjang, §16).
 */
enum ExpenseStatus: string
{
    case Requested = 'DIAJUKAN';
    case AwaitingFoundation = 'MENUNGGU_YAYASAN';
    case Approved = 'DISETUJUI';
    case Rejected = 'DITOLAK';
    case Cancelled = 'DIBATALKAN';
    case Realized = 'DIREALISASI';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Menunggu persetujuan',
            self::AwaitingFoundation => 'Menunggu persetujuan Yayasan',
            self::Approved => 'Disetujui, belum direalisasi',
            self::Rejected => 'Ditolak',
            self::Cancelled => 'Dibatalkan',
            self::Realized => 'Selesai (direalisasi)',
        };
    }
}
