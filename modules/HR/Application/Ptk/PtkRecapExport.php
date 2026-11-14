<?php

namespace Modules\HR\Application\Ptk;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Unduhan .xlsx rekap PTK (PRD-001 §21.2, HR-10b): lembar "Rekap PTK" (per pegawai) dan "Kelengkapan".
 * Semua nilai ditulis sebagai teks agar nol di depan NIK/NUPTK/NPSN/HP tidak hilang. Hak akses dicek pemanggil.
 */
final class PtkRecapExport
{
    /** Kolom lembar Rekap PTK: kunci baris → judul. */
    public const COLUMNS = [
        'organization' => 'Lembaga',
        'npsn' => 'NPSN',
        'jenjang' => 'Jenjang',
        'ownership' => 'Status lembaga',
        'district' => 'Kecamatan',
        'regency' => 'Kab/kota',
        'province' => 'Provinsi',
        'name' => 'Nama',
        'nik' => 'NIK',
        'nuptk' => 'NUPTK',
        'gender' => 'Jenis kelamin',
        'birth_place' => 'Tempat lahir',
        'birth_date' => 'Tanggal lahir',
        'mother_name' => 'Nama ibu kandung',
        'education' => 'Pendidikan terakhir',
        'education_major' => 'Jurusan',
        'sd_graduation_year' => 'Tahun lulus SD',
        'position' => 'Jabatan',
        'ptk_function' => 'Fungsi PTK',
        'civil_servant' => 'Status PNS',
        'employment_type' => 'Jenis pegawai',
        'classification' => 'Status kepegawaian',
        'start_date' => 'Mulai kerja',
        'certified' => 'Sertifikasi',
        'certification_year' => 'Tahun sertifikasi',
        'certificate_number' => 'No. sertifikat pendidik',
        'phone' => 'No. HP',
        'address' => 'Alamat',
    ];

    /**
     * @param  list<array<string, string|null>>  $rows
     * @param  list<array{organization: string, name: string, missing: string}>  $missing
     */
    public function write(array $rows, array $missing, string $title, bool $truncated, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->withFontBold(true);
        $header = (new Style)->withFontBold(true)->withBackgroundColor('DDEBF7');

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Rekap PTK');
            $sheet->setSheetView(new SheetView(freezeRow: 3));

            foreach (array_keys(self::COLUMNS) as $index => $key) {
                $sheet->setColumnWidth(match ($key) {
                    'organization', 'name', 'mother_name', 'address' => 30,
                    'nik', 'nuptk', 'certificate_number', 'phone', 'position', 'ptk_function' => 20,
                    default => 14,
                }, $index + 1);
            }

            $writer->addRow(Row::fromValuesWithStyle([$title.($truncated ? ' — DIPOTONG: hanya baris pertama, pilih satu lembaga' : '')], $bold));
            $writer->addRow(Row::fromValues(['Data pribadi (NIK, tempat & tanggal lahir, nama ibu kandung, HP, alamat) hanya terisi untuk pegawai yang boleh Anda lihat data pribadinya. Simpan file ini dengan hati-hati.']));
            $writer->addRow(Row::fromValuesWithStyle(array_values(self::COLUMNS), $header));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues(array_map(fn (string $key): string => (string) ($row[$key] ?? ''), array_keys(self::COLUMNS))));
            }

            $writer->addNewSheetAndMakeItCurrent();
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Kelengkapan');
            $sheet->setColumnWidth(30, 1);
            $sheet->setColumnWidth(30, 2);
            $sheet->setColumnWidth(70, 3);
            $writer->addRow(Row::fromValuesWithStyle(['Lembaga', 'Nama', 'Isian yang masih kosong'], $header));

            if ($missing === []) {
                $writer->addRow(Row::fromValues(['Semua data PTK sudah lengkap.']));
            }

            foreach ($missing as $item) {
                $writer->addRow(Row::fromValues([$item['organization'], $item['name'], $item['missing']]));
            }

            $writer->setCurrentSheet($writer->getSheets()[0]);
        } finally {
            $writer->close();
        }
    }
}
