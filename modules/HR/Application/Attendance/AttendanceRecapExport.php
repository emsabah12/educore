<?php

namespace Modules\HR\Application\Attendance;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Rekap presensi pegawai .xlsx (PRD-001 §14.4, HR-4c) — dua lembar [ASUMSI]:
 *
 * - "Rekap": satu baris per pegawai + baris jumlah.
 * - "Rincian": satu baris per pegawai per hari wajib/tercatat, tanpa keterangan.
 *
 * Hak akses dicek pemanggil.
 *
 * @phpstan-import-type Counts from AttendanceRecap
 * @phpstan-import-type EmployeeCounts from AttendanceRecap
 * @phpstan-import-type DayDetail from AttendanceRecap
 */
final class AttendanceRecapExport
{
    private const COLUMNS = [
        'required' => 'Hari wajib',
        'present' => 'Hadir',
        'late' => 'Terlambat (kali)',
        'late_minutes' => 'Terlambat (menit)',
        'early' => 'Pulang cepat (kali)',
        'early_minutes' => 'Pulang cepat (menit)',
        'sick' => 'Sakit',
        'permit' => 'Izin',
        'duty' => 'Dinas luar',
        'leave' => 'Cuti',
        'absent' => 'Alpa',
        'empty' => 'Belum diisi',
    ];

    public static function fileName(string $organizationCode, string $month): string
    {
        return 'rekap-presensi-pegawai-'.mb_strtolower($organizationCode).'-'.$month.'.xlsx';
    }

    /**
     * @param  array{rows: list<EmployeeCounts>, totals: Counts, details: list<DayDetail>}  $recap
     */
    public function write(array $recap, string $title, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $this->summary($writer, $recap, $title);
            $writer->addNewSheetAndMakeItCurrent();
            $this->details($writer, $recap['details']);
            $writer->setCurrentSheet($writer->getSheets()[0]);
        } finally {
            $writer->close();
        }
    }

    /**
     * @param  array{rows: list<EmployeeCounts>, totals: Counts}  $recap
     */
    private function summary(Writer $writer, array $recap, string $title): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Rekap');
        $sheet->setSheetView(new SheetView(freezeRow: 3));
        $sheet->setColumnWidth(30, 1);
        $sheet->setColumnWidth(16, 2);

        foreach (array_keys(array_values(self::COLUMNS)) as $index) {
            $sheet->setColumnWidth(12, $index + 3);
        }

        $bold = (new Style)->withFontBold(true);
        $writer->addRow(Row::fromValuesWithStyle([$title], $bold));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow($this->header(['Nama', 'Nomor induk', ...array_values(self::COLUMNS)]));

        foreach ($recap['rows'] as $row) {
            $writer->addRow(Row::fromValues([$row['name'], $row['employee_number'] ?? '', ...$this->values($row)]));
        }

        $writer->addRow(Row::fromValuesWithStyle(['Jumlah', '', ...$this->values($recap['totals'])], $bold));
    }

    /**
     * @param  list<DayDetail>  $details
     */
    private function details(Writer $writer, array $details): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Rincian');
        $sheet->setSheetView(new SheetView(freezeRow: 2));

        foreach ([12, 30, 28, 14, 11, 11, 12, 14] as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $writer->addRow($this->header(['Tanggal', 'Nama', 'Jam wajib', 'Status', 'Masuk', 'Pulang', 'Terlambat', 'Pulang cepat']));

        foreach ($details as $day) {
            $writer->addRow(Row::fromValues([
                $day['date'], $day['name'], $day['expected'], $day['status'],
                $day['check_in'] ?? '', $day['check_out'] ?? '', $day['late'], $day['early'],
            ]));
        }
    }

    /**
     * @param  Counts  $counts
     * @return list<int>
     */
    private function values(array $counts): array
    {
        $values = [];

        foreach (array_keys(self::COLUMNS) as $key) {
            $values[] = $counts[$key];
        }

        return $values;
    }

    /**
     * @param  list<string>  $titles
     */
    private function header(array $titles): Row
    {
        return Row::fromValuesWithStyle($titles, (new Style)->withFontBold(true)->withBackgroundColor('DDEBF7'));
    }
}
