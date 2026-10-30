<?php

namespace Modules\Finance\Application\Report;

use Modules\Finance\Application\Fee\Rupiah;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Unduhan rekap penerimaan .xlsx satu bulan (PRD-003 §6, FN-4b §18): lembar "Harian", "Per jenis biaya",
 * "Per lembaga" (bila lebih dari satu lembaga), dan "Transaksi" (semua kuitansi bulan itu, termasuk yang batal).
 * Hak akses dicek pemanggil.
 *
 * @phpstan-import-type Totals from ReceiptRecap
 * @phpstan-import-type DayRow from ReceiptRecap
 * @phpstan-import-type FeeTypeRow from ReceiptRecap
 * @phpstan-import-type OrganizationRow from ReceiptRecap
 * @phpstan-import-type Transaction from ReceiptRecap
 */
final class ReceiptRecapExport
{
    private const TEXT_FORMAT = '@';

    private const MONEY_FORMAT = '#,##0';

    public function __construct(
        private readonly ReceiptRecap $recap,
    ) {}

    public static function fileName(string $scope, string $month): string
    {
        $code = preg_replace('/[^A-Za-z0-9-]+/', '-', $scope) ?? 'lembaga';

        return 'rekap-penerimaan-'.strtolower($code).'-'.$month.'.xlsx';
    }

    /**
     * @param  array<string, string>  $organizations  id → nama lembaga yang direkap
     */
    public function write(string $tenantId, array $organizations, string $scopeLabel, string $month, string $monthLabel, string $path): void
    {
        $ids = array_keys($organizations);
        $summary = $this->recap->summary($tenantId, $ids, $month);
        $title = "Rekap penerimaan {$scopeLabel} — {$monthLabel}";

        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $this->daySheet($writer, $title, $summary['days'], $summary['totals']);
            $writer->addNewSheetAndMakeItCurrent();
            $this->feeTypeSheet($writer, $title, $summary['fee_types'], $summary['totals']['amount']);

            if (count($organizations) > 1) {
                $writer->addNewSheetAndMakeItCurrent();
                $this->organizationSheet($writer, $title, $summary['organizations'], $organizations, $summary['totals']);
            }

            $writer->addNewSheetAndMakeItCurrent();
            $this->transactionSheet($writer, $title, $this->recap->transactions($tenantId, $ids, $month), $organizations);
            $writer->setCurrentSheet($writer->getSheets()[0]);
        } finally {
            $writer->close();
        }
    }

    /**
     * @param  list<DayRow>  $days
     * @param  Totals  $totals
     */
    private function daySheet(Writer $writer, string $title, array $days, array $totals): void
    {
        $this->prepare($writer, 'Harian', [5, 14, 12, 16, 16, 16]);
        $writer->addRow(Row::fromValues([$title]));
        $writer->addRow($this->header(['No', 'Tanggal', 'Transaksi', 'Tunai (Rp)', 'Transfer (Rp)', 'Total (Rp)']));
        $money = (new Style)->withFormat(self::MONEY_FORMAT);

        foreach ($days as $index => $day) {
            $writer->addRow(new Row([
                Cell::fromValue($index + 1),
                Cell::fromValue($day['date']),
                Cell::fromValue($day['count']),
                Cell::fromValue($day['cash'], $money),
                Cell::fromValue($day['transfer'], $money),
                Cell::fromValue($day['total'], $money),
            ]));
        }

        $writer->addRow($this->totalRow(['', 'Total', $totals['count'], $totals['cash'], $totals['transfer'], $totals['amount']], [3, 4, 5]));

        if ($totals['voided_count'] > 0) {
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues(['', "Dibatalkan (tidak dihitung): {$totals['voided_count']} transaksi, ".Rupiah::format($totals['voided_amount'])]));
        }
    }

    /**
     * @param  list<FeeTypeRow>  $feeTypes
     */
    private function feeTypeSheet(Writer $writer, string $title, array $feeTypes, int $total): void
    {
        $this->prepare($writer, 'Per jenis biaya', [5, 28, 16]);
        $writer->addRow(Row::fromValues([$title]));
        $writer->addRow($this->header(['No', 'Jenis biaya', 'Diterima (Rp)']));
        $money = (new Style)->withFormat(self::MONEY_FORMAT);

        foreach ($feeTypes as $index => $feeType) {
            $writer->addRow(new Row([
                Cell::fromValue($index + 1),
                Cell::fromValue($feeType['name']),
                Cell::fromValue($feeType['amount'], $money),
            ]));
        }

        $writer->addRow($this->totalRow(['', 'Total', $total], [2]));
    }

    /**
     * @param  list<OrganizationRow>  $rows
     * @param  array<string, string>  $names
     * @param  Totals  $totals
     */
    private function organizationSheet(Writer $writer, string $title, array $rows, array $names, array $totals): void
    {
        $this->prepare($writer, 'Per lembaga', [5, 30, 12, 16]);
        $writer->addRow(Row::fromValues([$title]));
        $writer->addRow($this->header(['No', 'Lembaga', 'Transaksi', 'Total (Rp)']));
        $money = (new Style)->withFormat(self::MONEY_FORMAT);

        foreach ($rows as $index => $row) {
            $writer->addRow(new Row([
                Cell::fromValue($index + 1),
                Cell::fromValue($names[$row['organization_id']] ?? ''),
                Cell::fromValue($row['count']),
                Cell::fromValue($row['total'], $money),
            ]));
        }

        $writer->addRow($this->totalRow(['', 'Total', $totals['count'], $totals['amount']], [3]));
    }

    /**
     * @param  list<Transaction>  $transactions
     * @param  array<string, string>  $names
     */
    private function transactionSheet(Writer $writer, string $title, array $transactions, array $names): void
    {
        $this->prepare($writer, 'Transaksi', [5, 12, 22, 24, 28, 10, 14, 14, 40, 22, 10, 24]);
        $writer->addRow(Row::fromValues([$title]));
        $writer->addRow($this->header(['No', 'Tanggal', 'No. kuitansi', 'Lembaga', 'Nama', 'Kelas', 'Cara bayar', 'Jumlah (Rp)', 'Untuk pembayaran', 'Petugas', 'Status', 'Alasan batal']));
        $text = (new Style)->withFormat(self::TEXT_FORMAT);
        $money = (new Style)->withFormat(self::MONEY_FORMAT);

        foreach ($transactions as $index => $transaction) {
            $writer->addRow(new Row([
                Cell::fromValue($index + 1),
                Cell::fromValue($transaction['paid_on']),
                Cell::fromValue($transaction['receipt_number'], $text),
                Cell::fromValue($names[$transaction['organization_id']] ?? ''),
                Cell::fromValue($transaction['name']),
                Cell::fromValue($transaction['class'] ?? ''),
                Cell::fromValue($transaction['method']),
                Cell::fromValue($transaction['amount'], $money),
                Cell::fromValue(implode('; ', $transaction['items'])),
                Cell::fromValue($transaction['recorded_by'] ?? ''),
                Cell::fromValue($transaction['status'] === 'BATAL' ? 'Batal' : 'Sah'),
                Cell::fromValue($transaction['void_reason'] ?? ''),
            ]));
        }
    }

    /**
     * @param  list<int>  $widths
     */
    private function prepare(Writer $writer, string $name, array $widths): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName($name);
        $sheet->setSheetView(new SheetView(freezeRow: 3));

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
     * Baris total tebal; kolom $moneyColumns (0-based) diformat rupiah.
     *
     * @param  list<string|int>  $values
     * @param  list<int>  $moneyColumns
     */
    private function totalRow(array $values, array $moneyColumns): Row
    {
        $cells = [];

        foreach ($values as $index => $value) {
            $style = (new Style)->withFontBold(true);
            $cells[] = Cell::fromValue($value, in_array($index, $moneyColumns, true) ? $style->withFormat(self::MONEY_FORMAT) : $style);
        }

        return new Row($cells);
    }
}
