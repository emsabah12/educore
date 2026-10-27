<?php

namespace Modules\HR\Application\Employee;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Unduhan .xlsx HR-6a (PRD-001 §17): daftar pegawai (sesuai saringan halaman Pegawai) dan laporan
 * jumlah pegawai (satu lembar per pengelompokan). Hak akses & isi dicek pemanggil.
 *
 * @phpstan-import-type Report from EmployeeReport
 */
final class EmployeeExport
{
    /**
     * @param  list<array<string, mixed>>  $rows  dari EmployeeDirectory::export()['rows']
     */
    public function writeList(array $rows, string $title, bool $truncated, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Pegawai');
            $sheet->setSheetView(new SheetView(freezeRow: 3));

            foreach ([30, 14, 18, 10, 12, 18, 20, 18, 18, 34, 40, 12, 18, 16, 12, 16, 40] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }

            $bold = (new Style)->withFontBold(true);
            $writer->addRow(Row::fromValuesWithStyle([$title.($truncated ? ' — DIPOTONG: hanya baris pertama, persempit saringan' : '')], $bold));
            $writer->addRow(Row::fromValues(['Data pribadi hanya terisi untuk pegawai yang boleh Anda lihat data pribadinya. NIK tidak ikut diunduh.']));
            $writer->addRow(Row::fromValuesWithStyle([
                'Nama', 'Nomor induk', 'NUPTK', 'Status', 'Jenis kelamin', 'Pendidikan', 'Jurusan', 'Jenis pegawai',
                'Status kepegawaian', 'Penempatan utama', 'Penempatan lain', 'Mulai kerja', 'Masa kerja',
                'Tempat lahir', 'Tanggal lahir', 'No. HP', 'Alamat',
            ], (new Style)->withFontBold(true)->withBackgroundColor('DDEBF7')));

            foreach ($rows as $row) {
                $personal = is_array($row['personal'] ?? null) ? $row['personal'] : [];

                $writer->addRow(Row::fromValues(array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', [
                    $row['name'] ?? '', $row['employee_number'] ?? '', $row['nuptk'] ?? '', $row['status'] ?? '', $row['gender'] ?? '',
                    $row['education'] ?? '', $row['education_major'] ?? '', $row['employment_type'] ?? '', $row['classification'] ?? '',
                    $row['primary_placement'] ?? '', $row['other_placements'] ?? '', $row['start_date'] ?? '', $row['service_length'] ?? '',
                    $personal['birth_place'] ?? '', $personal['birth_date'] ?? '', $personal['phone'] ?? '', $personal['address'] ?? '',
                ])));
            }
        } finally {
            $writer->close();
        }
    }

    /**
     * @param  Report  $report
     */
    public function writeReport(array $report, string $title, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->withFontBold(true);
        $header = (new Style)->withFontBold(true)->withBackgroundColor('DDEBF7');

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Ringkasan');
            $sheet->setColumnWidth(40, 1);
            $sheet->setColumnWidth(14, 2);
            $writer->addRow(Row::fromValuesWithStyle([$title], $bold));
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues(['Pegawai aktif', $report['total']]));
            $writer->addRow(Row::fromValues(["Berhenti tahun {$report['year']}", $report['ended_this_year']]));

            foreach (EmployeeReport::GROUP_TITLES as $key => $groupTitle) {
                $writer->addNewSheetAndMakeItCurrent();
                $sheet = $writer->getCurrentSheet();
                $sheet->setName(mb_substr($groupTitle, 0, 31));
                $sheet->setColumnWidth(40, 1);
                $sheet->setColumnWidth(14, 2);
                $writer->addRow(Row::fromValuesWithStyle([$groupTitle, 'Jumlah'], $header));

                foreach ($report['groups'][$key] as $group) {
                    $writer->addRow(Row::fromValues([$group['label'], $group['count']]));
                }
            }

            $writer->setCurrentSheet($writer->getSheets()[0]);
        } finally {
            $writer->close();
        }
    }
}
