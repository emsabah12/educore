<?php

namespace Modules\Dormitory\Domain\Placement;

/**
 * Kategori penghuni (PRD-008 §5.1). Bukan hak akses.
 */
enum PlacementCategory: string
{
    /** Santri (siswa dengan pendaftaran terbuka di lembaga di bawah pemilik asrama). */
    case Resident = 'PENGHUNI';

    /** Pegawai aktif yang tinggal di kamar Santri + pengawas, memakai slot pengawas. */
    case Supervisor = 'PENGAWAS';

    public function label(): string
    {
        return match ($this) {
            self::Resident => 'Santri',
            self::Supervisor => 'Pengawas',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
