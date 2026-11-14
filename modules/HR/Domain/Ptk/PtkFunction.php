<?php

namespace Modules\HR\Domain\Ptk;

/**
 * Fungsi PTK untuk Simpatika — daftar tetap (PRD-001 §21.1, OD-HR-27).
 */
enum PtkFunction: string
{
    case GuruKelas = 'GURU_KELAS';
    case GuruMapel = 'GURU_MAPEL';
    case GuruBk = 'GURU_BK';
    case Kepala = 'KEPALA';
    case TenagaAdministrasi = 'TENAGA_ADMINISTRASI';
    case Pustakawan = 'PUSTAKAWAN';
    case Laboran = 'LABORAN';
    case Lainnya = 'LAINNYA';

    public function label(): string
    {
        return match ($this) {
            self::GuruKelas => 'Guru Kelas',
            self::GuruMapel => 'Guru Mapel',
            self::GuruBk => 'Guru BK',
            self::Kepala => 'Kepala Sekolah/Madrasah',
            self::TenagaAdministrasi => 'Tenaga Administrasi',
            self::Pustakawan => 'Pustakawan',
            self::Laboran => 'Laboran',
            self::Lainnya => 'Lainnya',
        };
    }
}
