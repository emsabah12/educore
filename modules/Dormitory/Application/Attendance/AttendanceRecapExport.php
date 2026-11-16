<?php

namespace Modules\Dormitory\Application\Attendance;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Unduhan rekap absen asrama bulanan (.xlsx, PRD-008 §22.3): lembar Rekap (per orang) dan Per kamar (sesi diisi).
 * Keterangan absen tidak ikut (bisa memuat hal pribadi).
 *
 * @phpstan-import-type PersonRow from AttendanceRecap
 * @phpstan-import-type RoomRow from AttendanceRecap
 */
final class AttendanceRecapExport
{
    public static function fileName(string $dormitoryCode, string $month, ?string $roomCode): string
    {
        return 'rekap-absen-'.strtolower($dormitoryCode).($roomCode === null ? '' : '-'.strtolower($roomCode)).'-'.$month.'.xlsx';
    }

    /**
     * @param  array{people: list<PersonRow>, rooms: list<RoomRow>, totals: array{H: int, I: int, S: int, T: int, total: int, percent: float|null}, days: int, kinds: int}  $recap
     */
    public function write(string $path, array $recap, string $title): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->withFontBold(true);
        $header = (new Style)->withFontBold(true)->withBackgroundColor('DDEBF7');

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Rekap');
            $sheet->setSheetView(new SheetView(freezeRow: 3));

            foreach ([5, 30, 10, 20, 8, 8, 8, 10, 14, 10] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }

            $writer->addRow(Row::fromValuesWithStyle([$title], $bold));
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValuesWithStyle(['No', 'Nama', 'Kategori', 'Kamar', 'Hadir', 'Izin', 'Sakit', 'Tidak ada', 'Sesi tercatat', '% hadir'], $header));

            foreach ($recap['people'] as $index => $person) {
                $writer->addRow(Row::fromValues([
                    $index + 1, $person['name'], $person['category_label'], $person['rooms'],
                    $person['H'], $person['I'], $person['S'], $person['T'], $person['total'], $person['percent'] ?? '–',
                ]));
            }

            $totals = $recap['totals'];
            $writer->addRow(Row::fromValuesWithStyle(['', 'Jumlah', '', '', $totals['H'], $totals['I'], $totals['S'], $totals['T'], $totals['total'], $totals['percent'] ?? '–'], $bold));

            $writer->addNewSheetAndMakeItCurrent();
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Per kamar');
            $sheet->setSheetView(new SheetView(freezeRow: 2));

            foreach ([24, 12, 14, 18] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }

            $writer->addRow(Row::fromValuesWithStyle(['Gedung', 'Kamar', 'Sesi diisi', 'Sesi seharusnya'], $header));

            foreach ($recap['rooms'] as $room) {
                $writer->addRow(Row::fromValues([$room['building'], $room['code'], $room['filled'], $room['expected']]));
            }
        } finally {
            $writer->close();
        }
    }
}
