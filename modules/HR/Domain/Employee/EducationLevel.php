<?php

namespace Modules\HR\Domain\Employee;

/**
 * Pendidikan terakhir (PRD-001 §4.3), pilihannya mengikuti formulir A05 Simpatika (OD-HR-29).
 */
enum EducationLevel: string
{
    case Sd = 'SD';
    case Smp = 'SMP';
    case Sma = 'SMA';
    case Smk = 'SMK';
    case D1 = 'D1';
    case D2 = 'D2';
    case D3 = 'D3';
    case D4 = 'D4';
    case S1 = 'S1';
    case S2 = 'S2';
    case S3 = 'S3';
    case Other = 'LAINNYA';

    /** Kode lama sebelum OD-HR-29 ("D4/S1" digabung) — tetap diterima saat impor sebagai S1. */
    public const LEGACY_D4_S1 = 'D4_S1';

    public function label(): string
    {
        return match ($this) {
            self::Sd => 'SD/MI',
            self::Smp => 'SMP/MTs',
            self::Sma => 'SMA/MA/sederajat',
            self::Smk => 'SMK',
            self::D1 => 'D1',
            self::D2 => 'D2',
            self::D3 => 'D3',
            self::D4 => 'D4',
            self::S1 => 'S1',
            self::S2 => 'S2',
            self::S3 => 'S3',
            self::Other => 'Lainnya',
        };
    }

    /**
     * Kode dari berkas impor: kode sekarang, atau kode lama `D4_S1` / tulisan `D4/S1` → S1.
     */
    public static function fromImport(string $code): ?self
    {
        $code = mb_strtoupper(trim($code));

        return in_array($code, [self::LEGACY_D4_S1, 'D4/S1'], true) ? self::S1 : self::tryFrom($code);
    }
}
