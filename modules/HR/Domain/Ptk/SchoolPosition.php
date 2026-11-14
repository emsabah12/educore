<?php

namespace Modules\HR\Domain\Ptk;

/**
 * Jabatan di madrasah/sekolah induk sesuai formulir A05 Simpatika (PRD-001 §21.3, OD-HR-28). Urutan = kolom formulir.
 */
enum SchoolPosition: string
{
    case Pengawas = 'PENGAWAS';
    case Kepala = 'KEPALA';
    case WakilKepala = 'WAKIL_KEPALA';
    case KepalaLab = 'KEPALA_LAB';
    case Laboran = 'LABORAN';
    case TeknisiLab = 'TEKNISI_LAB';
    case KepalaTu = 'KEPALA_TU';
    case Bendahara = 'BENDAHARA';
    case StafTu = 'STAF_TU';
    case KepalaPerpus = 'KEPALA_PERPUS';
    case Pustakawan = 'PUSTAKAWAN';
    case Pengemudi = 'PENGEMUDI';
    case Penjaga = 'PENJAGA';
    case Pesuruh = 'PESURUH';
    case Kebersihan = 'KEBERSIHAN';
    case Kebun = 'KEBUN';
    case KepalaProgram = 'KEPALA_PROGRAM';
    case Keahlian = 'KEAHLIAN';

    public function label(): string
    {
        return match ($this) {
            self::Pengawas => 'Pengawas Sekolah',
            self::Kepala => 'Kepala Madrasah/Sekolah',
            self::WakilKepala => 'Wakil Kepala Madrasah/Sekolah',
            self::KepalaLab => 'Kepala Laboratorium',
            self::Laboran => 'Laboran',
            self::TeknisiLab => 'Teknisi Laboratorium',
            self::KepalaTu => 'Kepala TU',
            self::Bendahara => 'Bendahara',
            self::StafTu => 'Staf TU',
            self::KepalaPerpus => 'Kepala Perpustakaan',
            self::Pustakawan => 'Pustakawan',
            self::Pengemudi => 'Pengemudi',
            self::Penjaga => 'Penjaga Sekolah',
            self::Pesuruh => 'Pesuruh',
            self::Kebersihan => 'Tenaga Kebersihan',
            self::Kebun => 'Tukang Kebun',
            self::KepalaProgram => 'Kepala Program (KaProg)',
            self::Keahlian => 'Keahlian',
        };
    }

    /** Tulisan pendek di kotak formulir A05. */
    public function formLabel(): string
    {
        return match ($this) {
            self::Pengawas => 'Pengawas Skh',
            self::Kepala => 'Ka Mad/Sek',
            self::WakilKepala => 'WaKa Mad/Sek',
            self::KepalaLab => 'Ka Lab',
            self::Laboran => 'Laboran',
            self::TeknisiLab => 'Tek. Lab',
            self::KepalaTu => 'Ka TU',
            self::Bendahara => 'Bendahara',
            self::StafTu => 'Staf TU',
            self::KepalaPerpus => 'Ka Perpus',
            self::Pustakawan => 'Pustakawan',
            self::Pengemudi => 'Pengemudi',
            self::Penjaga => 'Penjaga Skh',
            self::Pesuruh => 'Pesuruh',
            self::Kebersihan => 'T Kebersihan',
            self::Kebun => 'T Kebun',
            self::KepalaProgram => 'KaProg',
            self::Keahlian => 'Keahlian',
        };
    }
}
