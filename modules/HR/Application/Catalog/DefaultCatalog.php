<?php

namespace Modules\HR\Application\Catalog;

use Modules\HR\Domain\Catalog\CatalogKind;

/**
 * Isi awal katalog SDM untuk yayasan baru (PRD-001 §4.3, [ASUMSI]). Semuanya bisa diubah
 * nama atau dinonaktifkan oleh Admin SDM; kodenya tetap.
 */
final class DefaultCatalog
{
    /** Kode jenis dokumen awal yang punya masa berlaku. */
    public const DOCUMENT_TYPES_WITH_EXPIRY = ['KONTRAK'];

    /** Kode awal yang pilihan ya/tidak-nya (CatalogKind::flag) dicentang, per katalog. */
    public const FLAGGED = [
        'jenis-dokumen' => self::DOCUMENT_TYPES_WITH_EXPIRY,
        // HR-5b [ASUMSI, OWNER 2026-10-25: penerima honor JP = GTT/honorer].
        'jenis-pegawai' => ['HONORER'],
        'status-kepegawaian' => ['GTT'],
    ];

    /**
     * @return array<string, array<string, string>> nilai CatalogKind => [kode => nama]
     */
    public static function items(): array
    {
        return [
            CatalogKind::EmploymentType->value => [
                'TETAP' => 'Pegawai Tetap',
                'KONTRAK' => 'Pegawai Kontrak',
                'HONORER' => 'Honorer',
            ],
            CatalogKind::Classification->value => [
                'GTY' => 'Guru Tetap Yayasan',
                'GTT' => 'Guru Tidak Tetap',
                'PTY' => 'Pegawai Tetap Yayasan',
                'PTT' => 'Pegawai Tidak Tetap',
            ],
            CatalogKind::Position->value => [
                'KEPALA' => 'Kepala Sekolah/Madrasah',
                'WAKIL-KEPALA' => 'Wakil Kepala',
                'GURU' => 'Guru',
                'USTADZ' => 'Ustadz/Ustadzah',
                'WALI-KELAS' => 'Wali Kelas',
                'TU' => 'Tata Usaha',
                'BENDAHARA' => 'Bendahara',
                'OPERATOR' => 'Operator',
                'STAF-BIRO' => 'Staf Biro',
            ],
            // HR-2a (§15): Kontrak Kerja punya masa berlaku (DOCUMENT_TYPES_WITH_EXPIRY).
            CatalogKind::DocumentType->value => [
                'SK-PENGANGKATAN' => 'SK Pengangkatan',
                'SK-MUTASI' => 'SK Penempatan/Mutasi',
                'SK-JABATAN' => 'SK Jabatan',
                'KONTRAK' => 'Kontrak Kerja',
                'IJAZAH' => 'Ijazah',
                'SERTIFIKAT-PENDIDIK' => 'Sertifikat Pendidik',
                'LAINNYA' => 'Dokumen lainnya',
            ],
        ];
    }
}
