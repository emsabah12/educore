<?php

namespace Modules\Academic\Application\Teaching;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Rekap presensi mengajar .xlsx (PRD-002 §20.4, AC-6c) — dua lembar [ASUMSI]:
 *
 * - "Rekap": satu baris per guru (sama dengan halaman) + baris jumlah.
 * - "Rincian": satu baris per pertemuan, tanpa keterangan (bisa memuat hal pribadi).
 *
 * Hak akses dicek pemanggil.
 *
 * @phpstan-import-type RecapRow from TeachingRecap
 * @phpstan-import-type Lesson from Lessons
 */
final class TeachingRecapExport
{
    public static function fileName(string $organizationCode, string $month): string
    {
        return 'rekap-mengajar-'.mb_strtolower($organizationCode).'-'.$month.'.xlsx';
    }

    /**
     * @param  array{label: string, until: string|null, rows: list<RecapRow>, totals: RecapRow, lessons: list<Lesson>}  $recap
     */
    public function write(array $recap, string $organizationName, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $this->summarySheet($writer, $recap, $organizationName);
            $writer->addNewSheetAndMakeItCurrent();
            $this->detailSheet($writer, $recap['lessons']);
            $writer->setCurrentSheet($writer->getSheets()[0]);
        } finally {
            $writer->close();
        }
    }

    /**
     * @param  array{label: string, until: string|null, rows: list<RecapRow>, totals: RecapRow}  $recap
     */
    private function summarySheet(Writer $writer, array $recap, string $organizationName): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Rekap');
        $sheet->setSheetView(new SheetView(freezeRow: 4));

        foreach ([30, 11, 11, 11, 11, 13, 13, 12] as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $bold = (new Style)->withFontBold(true);
        $writer->addRow(Row::fromValuesWithStyle(["Rekap presensi mengajar — {$organizationName}"], $bold));
        $writer->addRow(Row::fromValues([$recap['label'].($recap['until'] === null ? '' : ' (dihitung sampai '.$recap['until'].')')]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow($this->header(['Guru', 'Terjadwal', 'Mengajar', 'Digantikan', 'Kosong', 'Belum dicatat', 'Menggantikan', '% Mengajar']));

        foreach ([...$recap['rows'], $recap['totals']] as $index => $row) {
            $values = [$row['name'], $row['scheduled'], $row['taught'], $row['substituted'], $row['empty'], $row['unrecorded'], $row['substituting'], $row['percent'] ?? ''];
            $writer->addRow($index === count($recap['rows']) ? Row::fromValuesWithStyle($values, $bold) : Row::fromValues($values));
        }
    }

    /**
     * @param  list<Lesson>  $lessons
     */
    private function detailSheet(Writer $writer, array $lessons): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Rincian');
        $sheet->setSheetView(new SheetView(freezeRow: 2));

        foreach ([12, 13, 14, 24, 28, 14, 28, 14, 24] as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $writer->addRow($this->header(['Tanggal', 'Jam', 'Kelas', 'Mapel', 'Pengampu', 'Status', 'Pengganti', 'Alasan', 'Dicatat oleh']));

        foreach ($lessons as $lesson) {
            $record = $lesson['record'];

            $writer->addRow(Row::fromValues([
                $lesson['date'],
                $lesson['start'].'–'.$lesson['end'],
                $lesson['class_name'],
                $lesson['subject'],
                $lesson['teacher']['name'] ?? '(tanpa pengampu)',
                $record['status_label'] ?? 'Belum dicatat',
                $record['substitute']['name'] ?? '',
                $record['reason_label'] ?? '',
                match ($record['source'] ?? null) {
                    null => '',
                    Lessons::SOURCE_TEACHER => 'Guru sendiri',
                    Lessons::SOURCE_AUTO => 'Otomatis (presensi siswa)',
                    default => $record['recorded_by'] ?? 'Petugas',
                },
            ]));
        }
    }

    /**
     * @param  list<string>  $titles
     */
    private function header(array $titles): Row
    {
        return Row::fromValuesWithStyle($titles, (new Style)->withFontBold(true)->withBackgroundColor('DDEBF7'));
    }
}
