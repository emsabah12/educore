<?php

namespace Modules\Finance\Application\Ledger;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Unduhan neraca saldo & buku besar .xlsx (PRD-007 §7, PB-2b). Hak akses dicek pemanggil.
 *
 * @phpstan-import-type Period from LedgerReports
 * @phpstan-import-type BalanceRow from LedgerReports
 */
final class LedgerExport
{
    private const MONEY_FORMAT = '#,##0';

    public function __construct(
        private readonly LedgerReports $reports,
    ) {}

    public static function fileName(string $kind, string $scope, string $from, string $to): string
    {
        $code = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $scope) ?? 'gabungan');

        return "{$kind}-{$code}-{$from}-{$to}.xlsx";
    }

    /**
     * @param  Period  $period
     */
    public function trialBalance(string $tenantId, ?string $scope, array $period, string $title, string $path): void
    {
        $report = $this->reports->trialBalance($tenantId, $scope, $period);
        $writer = $this->open($path, 'Neraca saldo', [12, 40, 16, 16, 16, 16, 16, 16]);

        try {
            $writer->addRow(Row::fromValues([$title]));
            $writer->addRow(Row::fromValues(["Periode {$period['from']} s.d. {$period['to']}"]));
            $writer->addRow($this->header(['Kode', 'Akun', 'Saldo awal debit', 'Saldo awal kredit', 'Mutasi debit', 'Mutasi kredit', 'Saldo akhir debit', 'Saldo akhir kredit']));

            foreach ($report['rows'] as $row) {
                if ($row['opening'] === 0 && $row['debit'] === 0 && $row['credit'] === 0) {
                    continue;
                }

                $writer->addRow($this->moneyRow([
                    $row['code'], $row['name'],
                    max(0, $row['opening']), max(0, -$row['opening']), $row['debit'], $row['credit'], max(0, $row['closing']), max(0, -$row['closing']),
                ], [2, 3, 4, 5, 6, 7]));
            }

            $totals = $report['totals'];
            $writer->addRow($this->moneyRow([
                '', 'Total', $totals['opening_debit'], $totals['opening_credit'], $totals['debit'], $totals['credit'], $totals['closing_debit'], $totals['closing_credit'],
            ], [2, 3, 4, 5, 6, 7], true));
        } finally {
            $writer->close();
        }
    }

    /**
     * @param  Period  $period
     * @param  array{account: array{id: string, code: string, name: string, normal_balance: string}, opening: int, debit: int, credit: int, closing: int}  $ledger
     */
    public function ledger(string $tenantId, ?string $scope, array $period, array $ledger, string $title, string $path): void
    {
        $sign = $ledger['account']['normal_balance'] === 'DEBIT' ? 1 : -1;
        $writer = $this->open($path, 'Buku besar', [12, 18, 50, 18, 22, 16, 16, 18]);

        try {
            $writer->addRow(Row::fromValues([$title]));
            $writer->addRow(Row::fromValues(["Periode {$period['from']} s.d. {$period['to']} · saldo normal ".($sign === 1 ? 'debit' : 'kredit')]));
            $writer->addRow($this->header(['Tanggal', 'Nomor', 'Uraian', 'Sumber', 'Lembaga', 'Debit', 'Kredit', 'Saldo']));
            $writer->addRow($this->moneyRow(['', '', 'Saldo awal', '', '', '', '', $sign * $ledger['opening']], [7], true));

            foreach ($this->reports->ledgerRows($tenantId, $ledger['account']['id'], $scope, $period, $ledger['opening']) as $row) {
                $writer->addRow($this->moneyRow([
                    $row['date'], $row['number'], $row['description'], $row['source_label'], $row['organization'],
                    $row['debit'] > 0 ? $row['debit'] : '', $row['credit'] > 0 ? $row['credit'] : '', $sign * $row['balance'],
                ], [5, 6, 7]));
            }

            $writer->addRow($this->moneyRow(['', '', 'Saldo akhir', '', '', $ledger['debit'], $ledger['credit'], $sign * $ledger['closing']], [5, 6, 7], true));
        } finally {
            $writer->close();
        }
    }

    /**
     * @param  list<int>  $widths
     */
    private function open(string $path, string $sheetName, array $widths): Writer
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName($sheetName);
        $sheet->setSheetView(new SheetView(freezeRow: 4));

        foreach ($widths as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        return $writer;
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
