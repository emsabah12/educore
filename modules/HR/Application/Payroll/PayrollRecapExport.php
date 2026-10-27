<?php

namespace Modules\HR\Application\Payroll;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Rekap gaji bulanan .xlsx untuk Keuangan (PRD-001 §16.3, HR-5c-1), dua lembar:
 * - "Rekap": satu baris per pegawai (kolom per kelompok) + Jumlah;
 * - "Rincian": satu baris per komponen per pegawai beserta dasar hitungnya.
 * Hak akses dicek pemanggil.
 *
 * @phpstan-import-type Payslip from PayrollCalculator
 */
final class PayrollRecapExport
{
    private const COLUMNS = [
        'position' => 'Dari jabatan',
        'fixed' => 'Komponen tetap',
        'present_day' => 'Per hari hadir',
        'teaching' => 'Honor JP',
        'adjustment_earning' => 'Penyesuaian (+)',
        'fixed_deduction' => 'Potongan tetap',
        'attendance_deduction' => 'Potongan presensi',
        'adjustment_deduction' => 'Penyesuaian (−)',
    ];

    private const GROUP_LABELS = [
        'JABATAN' => 'Jabatan',
        'TETAP' => 'Komponen tetap',
        'HARI_HADIR' => 'Per hari hadir',
        'HONOR_JP' => 'Honor JP',
        'POTONGAN_PRESENSI' => 'Potongan presensi',
        'PENYESUAIAN' => 'Penyesuaian',
    ];

    public static function fileName(string $month, ?string $organizationCode): string
    {
        return 'rekap-gaji-'.($organizationCode === null ? '' : mb_strtolower($organizationCode).'-').$month.'.xlsx';
    }

    /**
     * @param  list<Payslip>  $payslips
     */
    public function write(array $payslips, string $title, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $this->summary($writer, $payslips, $title);
            $writer->addNewSheetAndMakeItCurrent();
            $this->details($writer, $payslips);
            $writer->setCurrentSheet($writer->getSheets()[0]);
        } finally {
            $writer->close();
        }
    }

    /**
     * @param  list<Payslip>  $payslips
     */
    private function summary(Writer $writer, array $payslips, string $title): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Rekap');
        $sheet->setSheetView(new SheetView(freezeRow: 3));
        $sheet->setColumnWidth(30, 1);
        $sheet->setColumnWidth(14, 2);
        $sheet->setColumnWidth(24, 3);

        for ($index = 4; $index <= 4 + count(self::COLUMNS) + 2; $index++) {
            $sheet->setColumnWidth(16, $index);
        }

        $bold = (new Style)->withFontBold(true);
        $writer->addRow(Row::fromValuesWithStyle([$title], $bold));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow($this->header(['Nama', 'Nomor induk', 'Lembaga', ...array_values(self::COLUMNS), 'Total pendapatan', 'Total potongan', 'Bersih']));

        $totals = array_fill_keys([...array_keys(self::COLUMNS), 'earning', 'deduction', 'net'], 0);

        foreach ($payslips as $payslip) {
            $columns = PayrollCalculator::columns($payslip['lines']);
            $values = [];

            foreach (array_keys(self::COLUMNS) as $key) {
                $values[] = $columns[$key];
                $totals[$key] += $columns[$key];
            }

            $totals['earning'] += $payslip['earning'];
            $totals['deduction'] += $payslip['deduction'];
            $totals['net'] += $payslip['net'];

            $writer->addRow(Row::fromValues([
                $payslip['name'], $payslip['employee_number'] ?? '', $payslip['organization'] ?? '',
                ...$values, $payslip['earning'], $payslip['deduction'], $payslip['net'],
            ]));
        }

        $writer->addRow(Row::fromValuesWithStyle(['Jumlah', '', '', ...array_values($totals)], $bold));
    }

    /**
     * @param  list<Payslip>  $payslips
     */
    private function details(Writer $writer, array $payslips): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Rincian');
        $sheet->setSheetView(new SheetView(freezeRow: 2));

        foreach ([30, 18, 34, 40, 12, 16] as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $writer->addRow($this->header(['Nama', 'Kelompok', 'Komponen', 'Dasar hitung', 'Jenis', 'Nominal']));

        foreach ($payslips as $payslip) {
            foreach ($payslip['lines'] as $line) {
                $writer->addRow(Row::fromValues([
                    $payslip['name'],
                    self::GROUP_LABELS[$line['group']] ?? $line['group'],
                    $line['label'],
                    $line['detail'] ?? '',
                    $line['kind'] === 'POTONGAN' ? 'Potongan' : 'Pendapatan',
                    $line['amount'],
                ]));
            }
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
