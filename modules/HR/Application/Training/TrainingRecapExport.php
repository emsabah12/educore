<?php

namespace Modules\HR\Application\Training;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Unduhan .xlsx rekap jam pelatihan per pegawai per tahun (PRD-001 §19.1, HR-8a). Hak akses dicek pemanggil.
 *
 * @phpstan-import-type RecapRow from TrainingRecords
 */
final class TrainingRecapExport
{
    /**
     * @param  list<RecapRow>  $rows
     */
    public function write(array $rows, string $title, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Rekap pelatihan');
            $sheet->setSheetView(new SheetView(freezeRow: 3));

            foreach ([6, 32, 16, 30, 14, 14] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }

            $writer->addRow(Row::fromValuesWithStyle([$title], (new Style)->withFontBold(true)));
            $writer->addRow(Row::fromValues(['Hanya pelatihan yang sudah diterima; tahun dari tanggal selesai.']));
            $writer->addRow(Row::fromValuesWithStyle(
                ['No', 'Nama', 'Nomor induk', 'Penempatan utama', 'Jumlah kegiatan', 'Total jam'],
                (new Style)->withFontBold(true)->withBackgroundColor('DDEBF7'),
            ));

            foreach ($rows as $index => $row) {
                $writer->addRow(Row::fromValues([
                    $index + 1, $row['employee_name'], $row['employee_number'] ?? '', $row['organization'] ?? '', $row['count'], $row['hours'],
                ]));
            }

            $writer->addRow(Row::fromValuesWithStyle(
                ['', 'Jumlah', '', '', array_sum(array_column($rows, 'count')), array_sum(array_column($rows, 'hours'))],
                (new Style)->withFontBold(true),
            ));
        } finally {
            $writer->close();
        }
    }
}
