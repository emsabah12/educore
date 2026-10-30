<?php

namespace Modules\Finance\Application\Report;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Unduhan laporan arus kas .xlsx (PRD-004 §6, KS-4): "Ringkasan", "Per bulan" (periode tahunan), "Per kas" (lebih
 * dari satu kas), "Transaksi". Hak akses dicek pemanggil.
 *
 * @phpstan-import-type Report from CashFlowReport
 */
final class CashFlowExport
{
    private const MONEY_FORMAT = '#,##0';

    public function __construct(
        private readonly CashFlowReport $report,
    ) {}

    public static function fileName(string $scope, string $period): string
    {
        $code = preg_replace('/[^A-Za-z0-9-]+/', '-', $scope) ?? 'kas';

        return 'laporan-kas-'.strtolower($code).'-'.$period.'.xlsx';
    }

    /**
     * @param  list<string>  $organizationIds
     * @param  array<string, string>  $bookNames  organization_id ('' untuk Yayasan) → nama kas
     */
    public function write(string $tenantId, array $organizationIds, bool $includeYayasan, string $period, string $title, array $bookNames, string $path): void
    {
        $report = $this->report->build($tenantId, $organizationIds, $includeYayasan, $period);

        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $this->summarySheet($writer, $title, $report);

            if ($report['months'] !== []) {
                $writer->addNewSheetAndMakeItCurrent();
                $this->prepare($writer, 'Per bulan', [20, 18, 18, 18]);
                $writer->addRow(Row::fromValues([$title]));
                $writer->addRow($this->header(['Bulan', 'Pemasukan (Rp)', 'Pengeluaran (Rp)', 'Selisih (Rp)']));

                foreach ($report['months'] as $month) {
                    $writer->addRow($this->moneyRow([$month['month'], $month['income'], $month['expense'], $month['income'] - $month['expense']], [1, 2, 3]));
                }

                $writer->addRow($this->moneyRow(['Total', $report['income_total'], $report['expense_total'], $report['income_total'] - $report['expense_total']], [1, 2, 3], true));
            }

            if (count($report['books']) > 1) {
                $writer->addNewSheetAndMakeItCurrent();
                $this->prepare($writer, 'Per kas', [30, 16, 16, 16, 16, 16, 16]);
                $writer->addRow(Row::fromValues([$title]));
                $writer->addRow($this->header(['Kas', 'Saldo awal', 'Pemasukan', 'Pengeluaran', 'Mutasi masuk', 'Mutasi keluar', 'Saldo akhir']));

                foreach ($report['books'] as $book) {
                    $writer->addRow($this->moneyRow([
                        $bookNames[$book['organization_id'] ?? ''] ?? '', $book['opening'], $book['income'], $book['expense'], $book['transfer_in'], $book['transfer_out'], $book['closing'],
                    ], [1, 2, 3, 4, 5, 6]));
                }
            }

            $writer->addNewSheetAndMakeItCurrent();
            $this->prepare($writer, 'Transaksi', [12, 26, 22, 24, 50, 16, 16]);
            $writer->addRow(Row::fromValues([$title]));
            $writer->addRow($this->header(['Tanggal', 'Kas', 'Rekening', 'Kategori', 'Uraian', 'Masuk (Rp)', 'Keluar (Rp)']));

            foreach ($this->report->transactions($tenantId, $organizationIds, $includeYayasan, $period) as $entry) {
                $incoming = $entry['direction'] === 'MASUK';
                $writer->addRow($this->moneyRow([
                    $entry['date'], $bookNames[$entry['organization_id'] ?? ''] ?? '', $entry['account'], $entry['category'], $entry['description'],
                    $incoming ? $entry['amount'] : '', $incoming ? '' : $entry['amount'],
                ], [5, 6]));
            }

            $writer->setCurrentSheet($writer->getSheets()[0]);
        } finally {
            $writer->close();
        }
    }

    /**
     * @param  Report  $report
     */
    private function summarySheet(Writer $writer, string $title, array $report): void
    {
        $this->prepare($writer, 'Ringkasan', [40, 18], freeze: false);
        $writer->addRow(Row::fromValues([$title]));
        $writer->addRow(Row::fromValues(["Periode {$report['from']} s.d. {$report['to']}"]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow($this->moneyRow(['Saldo awal', $report['opening']], [1], true));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow($this->header(['Pemasukan', 'Jumlah (Rp)']));

        foreach ($report['income'] as $row) {
            $writer->addRow($this->moneyRow([$row['category'], $row['amount']], [1]));
        }

        $writer->addRow($this->moneyRow(['Total pemasukan', $report['income_total']], [1], true));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow($this->header(['Pengeluaran', 'Jumlah (Rp)']));

        foreach ($report['expense'] as $row) {
            $writer->addRow($this->moneyRow([$row['category'], $row['amount']], [1]));
        }

        $writer->addRow($this->moneyRow(['Total pengeluaran', $report['expense_total']], [1], true));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow($this->moneyRow(['Mutasi masuk', $report['transfer_in']], [1]));
        $writer->addRow($this->moneyRow(['Mutasi keluar', $report['transfer_out']], [1]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow($this->moneyRow(['Saldo akhir', $report['closing']], [1], true));
    }

    /**
     * @param  list<int>  $widths
     */
    private function prepare(Writer $writer, string $name, array $widths, bool $freeze = true): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName($name);

        if ($freeze) {
            $sheet->setSheetView(new SheetView(freezeRow: 3));
        }

        foreach ($widths as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
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
     * @param  list<string|int>  $values
     * @param  list<int>  $moneyColumns
     */
    private function moneyRow(array $values, array $moneyColumns, bool $bold = false): Row
    {
        $cells = [];

        foreach ($values as $index => $value) {
            $style = $bold ? (new Style)->withFontBold(true) : new Style;
            $cells[] = Cell::fromValue($value, in_array($index, $moneyColumns, true) ? $style->withFormat(self::MONEY_FORMAT) : $style);
        }

        return new Row($cells);
    }
}
