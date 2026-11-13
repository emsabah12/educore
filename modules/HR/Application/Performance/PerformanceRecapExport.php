<?php

namespace Modules\HR\Application\Performance;

use Modules\HR\Domain\Performance\Predicate;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Unduhan .xlsx rekap penilaian kinerja satu periode (PRD-001 §19.2, HR-8b-2): lembar **Rekap** (per lembaga) dan
 * **Rincian** (per pegawai, tanpa catatan kekuatan/saran). Hak akses dicek pemanggil.
 *
 * @phpstan-import-type SummaryRow from PerformanceRecap
 * @phpstan-import-type DetailRow from PerformanceRecap
 */
final class PerformanceRecapExport
{
    /**
     * @param  list<SummaryRow>  $summary
     * @param  list<DetailRow>  $details
     */
    public function write(array $summary, array $details, string $title, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->withFontBold(true);
        $header = (new Style)->withFontBold(true)->withBackgroundColor('DDEBF7');

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Rekap');
            $sheet->setSheetView(new SheetView(freezeRow: 3));

            foreach ([6, 32, 14, 12, 12, 12, 11, 11, 11, 11, 11] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }

            $predicates = Predicate::cases();
            $writer->addRow(Row::fromValuesWithStyle([$title], $bold));
            $writer->addRow(Row::fromValues(['Pegawai aktif = penempatan utama saat ini; Dinilai = penilaian di lembaga itu pada periode ini.']));
            $writer->addRow(Row::fromValuesWithStyle(
                ['No', 'Lembaga', 'Pegawai aktif', 'Dinilai', 'Belum', 'Rata-rata', ...array_map(fn (Predicate $predicate): string => $predicate->label(), $predicates)],
                $header,
            ));

            foreach ($summary as $index => $row) {
                $writer->addRow(Row::fromValues([
                    $index + 1,
                    $row['organization'],
                    $row['active'],
                    $row['assessed'],
                    max(0, $row['active'] - $row['assessed']),
                    $row['average'] ?? '',
                    ...array_map(fn (Predicate $predicate): int => $row['predicates'][$predicate->value] ?? 0, $predicates),
                ]));
            }

            $writer->addRow(Row::fromValuesWithStyle([
                '',
                'Jumlah',
                array_sum(array_column($summary, 'active')),
                array_sum(array_column($summary, 'assessed')),
                array_sum(array_map(fn (array $row): int => max(0, $row['active'] - $row['assessed']), $summary)),
                '',
                ...array_map(fn (Predicate $predicate): int => array_sum(array_map(fn (array $row): int => $row['predicates'][$predicate->value] ?? 0, $summary)), $predicates),
            ], $bold));

            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName('Rincian');
            $sheet->setSheetView(new SheetView(freezeRow: 2));

            foreach ([6, 30, 16, 28, 10, 60, 12, 14, 26, 14] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }

            $writer->addRow(Row::fromValuesWithStyle([$title], $bold));
            $writer->addRow(Row::fromValuesWithStyle(
                ['No', 'Nama', 'Nomor induk', 'Lembaga', 'Kelompok', 'Nilai per komponen (bobot)', 'Nilai akhir', 'Predikat', 'Penilai', 'Tanggal dinilai'],
                $header,
            ));

            foreach ($details as $index => $row) {
                $writer->addRow(Row::fromValues([
                    $index + 1,
                    $row['employee_name'],
                    $row['employee_number'] ?? '',
                    $row['organization'],
                    $row['group_label'],
                    $row['scores'],
                    $row['final_score'],
                    $row['predicate_label'],
                    $row['assessed_by'] ?? '',
                    $row['assessed_on'],
                ]));
            }
        } finally {
            $writer->close();
        }
    }
}
