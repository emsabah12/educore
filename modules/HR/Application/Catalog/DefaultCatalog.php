<?php

namespace Modules\HR\Application\Catalog;

use Modules\HR\Domain\Catalog\CatalogKind;

/**
 * Isi awal katalog SDM untuk yayasan baru (PRD-001 §4.3, [ASUMSI]). Semuanya bisa diubah
 * nama atau dinonaktifkan oleh Admin SDM; kodenya tetap.
 */
final class DefaultCatalog
{
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
        ];
    }
}
