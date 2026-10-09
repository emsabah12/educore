<?php

namespace Modules\Core\Domain\Organization;

/**
 * Kode jenis lembaga (PRD-000 §4.5). Dipakai juga sebagai filter
 * penugasan fungsional, mis. Koordinator MDA (PRD-000 §4.4).
 */
enum Jenjang: string
{
    case Smp = 'SMP';
    case Mts = 'MTS';
    case Smk = 'SMK';
    case Ma = 'MA';
    case Mda = 'MDA';
    case Bahasa = 'BAHASA';
    case AlQuran = 'ALQURAN';
    case Ponpes = 'PONPES';

    /**
     * Nama tampilan dalam Bahasa Indonesia.
     */
    public function label(): string
    {
        return match ($this) {
            self::Smp => 'SMP',
            self::Mts => 'MTs',
            self::Smk => 'SMK',
            self::Ma => 'MA',
            self::Mda => 'MDA',
            self::Bahasa => 'Bahasa',
            self::AlQuran => "Al-Qur'an",
            self::Ponpes => 'Pondok Pesantren',
        };
    }
}
