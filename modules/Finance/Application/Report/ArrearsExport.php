<?php

namespace Modules\Finance\Application\Report;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Unduhan tunggakan .xlsx satu lembaga (PRD-003 §6, FN-4a §17), tiga lembar: "Per siswa", "Per kelas",
 * "Rincian" (setiap tagihan yang menunggak). Saringan kelas ikut berlaku. Hak akses dicek pemanggil.
 *
 * @phpstan-import-type StudentArrears from Arrears
 * @phpstan-import-type ClassArrears from Arrears
 * @phpstan-import-type InvoiceArrears from Arrears
 */
final class ArrearsExport
{
    private const TEXT_FORMAT = '@';

    private const MONEY_FORMAT = '#,##0';

    public function __construct(
        private readonly Arrears $arrears,
    ) {}

    public static function fileName(string $organizationCode, string $today): string
    {
        $code = preg_replace('/[^A-Za-z0-9-]+/', '-', $organizationCode) ?? 'lembaga';

        return 'tunggakan-'.strtolower($code).'-'.$today.'.xlsx';
    }

    public function write(string $tenantId, string $organizationId, string $organizationName, string $today, ?string $class, string $path): void
    {
        $data = $this->arrears->forOrganization($tenantId, $organizationId, $today, $class);
        $title = "Tunggakan {$organizationName} per {$today}".match ($class) {
            null => '',
            Arrears::NO_CLASS, Arrears::LEFT => ' — '.mb_strtolower(Arrears::groupLabel($class)),
            default => ' — kelas '.$class,
        };

        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $this->studentSheet($writer, $title, $data['students']);
            $writer->addNewSheetAndMakeItCurrent();
            $this->classSheet($writer, $title, $data['classes'], $data['total']);
            $writer->addNewSheetAndMakeItCurrent();
            $this->invoiceSheet($writer, $title, $data['students'], $data['invoices']);
            $writer->setCurrentSheet($writer->getSheets()[0]);
        } finally {
            $writer->close();
        }
    }

    /**
     * @param  list<StudentArrears>  $students
     */
    private function studentSheet(Writer $writer, string $title, array $students): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Per siswa');
        $sheet->setSheetView(new SheetView(freezeRow: 3));

        foreach ([5, 30, 14, 14, 12, 12, 10, 16, 16] as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $writer->addRow(Row::fromValues([$title]));
        $writer->addRow($this->header(['No', 'Nama', 'NIS', 'NISN', 'Kelas', 'Status', 'Tagihan', 'Tunggakan (Rp)', 'Jatuh tempo terlama']));
        $text = (new Style)->withFormat(self::TEXT_FORMAT);
        $money = (new Style)->withFormat(self::MONEY_FORMAT);
        $total = 0;

        foreach ($students as $index => $student) {
            $total += $student['total'];
            $writer->addRow(new Row([
                Cell::fromValue($index + 1),
                Cell::fromValue($student['name']),
                Cell::fromValue($student['student_number'] ?? '', $text),
                Cell::fromValue($student['nisn'] ?? '', $text),
                Cell::fromValue($student['class'] ?? ''),
                Cell::fromValue($student['active'] ? 'Aktif' : 'Sudah keluar'),
                Cell::fromValue($student['invoices']),
                Cell::fromValue($student['total'], $money),
                Cell::fromValue($student['oldest_due']),
            ]));
        }

        $writer->addRow($this->totalRow(['', 'Total', '', '', '', '', '', $total, ''], 7));
    }

    /**
     * @param  list<ClassArrears>  $classes
     */
    private function classSheet(Writer $writer, string $title, array $classes, int $total): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Per kelas');
        $sheet->setSheetView(new SheetView(freezeRow: 3));

        foreach ([5, 16, 12, 10, 16] as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $writer->addRow(Row::fromValues([$title]));
        $writer->addRow($this->header(['No', 'Kelas', 'Siswa', 'Tagihan', 'Tunggakan (Rp)']));
        $money = (new Style)->withFormat(self::MONEY_FORMAT);

        foreach ($classes as $index => $class) {
            $writer->addRow(new Row([
                Cell::fromValue($index + 1),
                Cell::fromValue($class['label']),
                Cell::fromValue($class['students']),
                Cell::fromValue($class['invoices']),
                Cell::fromValue($class['total'], $money),
            ]));
        }

        $writer->addRow($this->totalRow(['', 'Total', '', '', $total], 4));
    }

    /**
     * @param  list<StudentArrears>  $students
     * @param  list<InvoiceArrears>  $invoices
     */
    private function invoiceSheet(Writer $writer, string $title, array $students, array $invoices): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Rincian');
        $sheet->setSheetView(new SheetView(freezeRow: 3));

        foreach ([5, 30, 14, 12, 32, 14, 14, 14, 14] as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $writer->addRow(Row::fromValues([$title]));
        $writer->addRow($this->header(['No', 'Nama', 'NIS', 'Kelas', 'Tagihan', 'Jatuh tempo', 'Nominal (Rp)', 'Dibayar (Rp)', 'Sisa (Rp)']));
        $text = (new Style)->withFormat(self::TEXT_FORMAT);
        $money = (new Style)->withFormat(self::MONEY_FORMAT);
        $byId = [];

        foreach ($students as $student) {
            $byId[$student['enrollment_id']] = $student;
        }

        foreach ($invoices as $index => $invoice) {
            $student = $byId[$invoice['enrollment_id']] ?? null;
            $writer->addRow(new Row([
                Cell::fromValue($index + 1),
                Cell::fromValue($student['name'] ?? ''),
                Cell::fromValue($student['student_number'] ?? '', $text),
                Cell::fromValue($student['class'] ?? ''),
                Cell::fromValue($invoice['description']),
                Cell::fromValue($invoice['due_date']),
                Cell::fromValue($invoice['amount'], $money),
                Cell::fromValue($invoice['paid_amount'], $money),
                Cell::fromValue($invoice['outstanding'], $money),
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

    /**
     * Baris total tebal; kolom ke-$moneyColumn (0-based) diformat rupiah.
     *
     * @param  list<string|int>  $values
     */
    private function totalRow(array $values, int $moneyColumn): Row
    {
        $cells = [];

        foreach ($values as $index => $value) {
            $style = (new Style)->withFontBold(true);
            $cells[] = Cell::fromValue($value, $index === $moneyColumn ? $style->withFormat(self::MONEY_FORMAT) : $style);
        }

        return new Row($cells);
    }
}
