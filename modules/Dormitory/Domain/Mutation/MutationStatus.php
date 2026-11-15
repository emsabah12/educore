<?php

namespace Modules\Dormitory\Domain\Mutation;

/** Status mutasi (PRD-008 §8.1, §18.1). Tidak ada "disetujui tetapi belum dilaksanakan". */
enum MutationStatus: string
{
    case Requested = 'DIAJUKAN';
    case Executed = 'DILAKSANAKAN';
    case Rejected = 'DITOLAK';
    case Cancelled = 'DIBATALKAN';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Menunggu persetujuan',
            self::Executed => 'Dilaksanakan',
            self::Rejected => 'Ditolak',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
