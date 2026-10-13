<?php

namespace Modules\HR\Domain\Catalog;

use Illuminate\Database\Eloquent\Builder;

/**
 * Tiga katalog SDM per yayasan (PRD-001 §4.3). Nilai enum dipakai di URL halaman Katalog SDM.
 */
enum CatalogKind: string
{
    case EmploymentType = 'jenis-pegawai';
    case Classification = 'status-kepegawaian';
    case Position = 'jabatan';

    public function label(): string
    {
        return match ($this) {
            self::EmploymentType => 'Jenis pegawai',
            self::Classification => 'Status kepegawaian',
            self::Position => 'Jabatan',
        };
    }

    /**
     * Penjelasan singkat untuk halaman Katalog SDM.
     */
    public function description(): string
    {
        return match ($this) {
            self::EmploymentType => 'Bentuk hubungan kerja, mis. Tetap, Kontrak, Honorer.',
            self::Classification => 'Status kepegawaian yayasan, mis. GTY, GTT, PTY, PTT. Boleh dikosongkan di data pegawai.',
            self::Position => 'Jabatan di lembaga. Jabatan tidak memberi hak akses apa pun di aplikasi.',
        };
    }

    /**
     * Jenis subjek di audit log.
     */
    public function auditSubject(): string
    {
        return match ($this) {
            self::EmploymentType => 'hr_employment_type',
            self::Classification => 'hr_employment_classification',
            self::Position => 'hr_position',
        };
    }

    /**
     * Query model katalog jenis ini (ikut tersaring ke yayasan aktif bila konteks kerja ada).
     *
     * @return Builder<EmploymentType>|Builder<EmploymentClassification>|Builder<Position>
     */
    public function query(): Builder
    {
        return match ($this) {
            self::EmploymentType => EmploymentType::query(),
            self::Classification => EmploymentClassification::query(),
            self::Position => Position::query(),
        };
    }

    /**
     * Item baru (belum disimpan) untuk katalog jenis ini.
     */
    public function newItem(): CatalogItem
    {
        return match ($this) {
            self::EmploymentType => new EmploymentType,
            self::Classification => new EmploymentClassification,
            self::Position => new Position,
        };
    }
}
