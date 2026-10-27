<?php

namespace Modules\HR\Domain\Payroll;

/**
 * Cara hitung komponen gaji (PRD-001 §16.1):
 * - Tetap per bulan: nominal per pegawai;
 * - Per hari hadir: nominal per pegawai × jumlah hari Hadir + Dinas luar di rekap presensi bulan itu (HR-4c);
 * - Menurut jabatan (HR-5a-2): dari tarif jabatan, dijumlahkan untuk setiap jabatan yang sedang dipegang.
 */
enum PayComponentBasis: string
{
    case Monthly = 'TETAP';
    case PerPresentDay = 'PER_HARI_HADIR';
    case PerPosition = 'MENURUT_JABATAN';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Tetap per bulan',
            self::PerPresentDay => 'Per hari hadir',
            self::PerPosition => 'Menurut jabatan',
        };
    }

    /** Nominalnya diisi per pegawai (bukan dari tarif jabatan). */
    public function perEmployee(): bool
    {
        return $this !== self::PerPosition;
    }
}
