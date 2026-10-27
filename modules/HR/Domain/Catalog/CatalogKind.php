<?php

namespace Modules\HR\Domain\Catalog;

use Illuminate\Database\Eloquent\Builder;

/**
 * Katalog SDM per yayasan (PRD-001 §4.3), ditambah jenis dokumen (§15, HR-2a). Nilai enum dipakai di
 * URL halaman Katalog SDM.
 */
enum CatalogKind: string
{
    case EmploymentType = 'jenis-pegawai';
    case Classification = 'status-kepegawaian';
    case Position = 'jabatan';
    case DocumentType = 'jenis-dokumen';

    public function label(): string
    {
        return match ($this) {
            self::EmploymentType => 'Jenis pegawai',
            self::Classification => 'Status kepegawaian',
            self::Position => 'Jabatan',
            self::DocumentType => 'Jenis dokumen',
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
            self::DocumentType => 'Jenis dokumen & SK pegawai, mis. SK Pengangkatan, Kontrak Kerja. Jenis yang punya masa berlaku masuk pengingat dokumen akan habis.',
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
            self::DocumentType => 'hr_document_type',
        };
    }

    /**
     * Query model katalog jenis ini (ikut tersaring ke yayasan aktif bila konteks kerja ada).
     *
     * @return Builder<EmploymentType>|Builder<EmploymentClassification>|Builder<Position>|Builder<DocumentType>
     */
    public function query(): Builder
    {
        return match ($this) {
            self::EmploymentType => EmploymentType::query(),
            self::Classification => EmploymentClassification::query(),
            self::Position => Position::query(),
            self::DocumentType => DocumentType::query(),
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
            self::DocumentType => new DocumentType,
        };
    }

    /**
     * Pilihan ya/tidak tambahan di katalog ini, atau null:
     * - jenis dokumen: "punya masa berlaku" (HR-2a, `has_expiry`);
     * - jenis pegawai & status kepegawaian: "menerima honor per JP" (HR-5b, `receives_teaching_pay`).
     *
     * @return array{column: string, label: string, badge: string}|null
     */
    public function flag(): ?array
    {
        return match ($this) {
            self::DocumentType => [
                'column' => 'has_expiry',
                'label' => 'Punya masa berlaku (tanggal "berlaku sampai" wajib diisi; masuk pengingat dokumen akan habis).',
                'badge' => 'Punya masa berlaku',
            ],
            self::EmploymentType, self::Classification => [
                'column' => 'receives_teaching_pay',
                'label' => 'Menerima honor per JP (honor mengajar dihitung di rekap gaji).',
                'badge' => 'Honor per JP',
            ],
            self::Position => null,
        };
    }
}
