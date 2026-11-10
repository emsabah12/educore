<?php

namespace Modules\Finance\Application\Ledger;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Unduhan laporan keuangan ISAK 35 .xlsx (PRD-007 §7, PB-3): empat lembar — posisi keuangan, penghasilan komprehensif,
 * perubahan aset neto, arus kas. Hak akses dicek pemanggil.
 *
 * @phpstan-import-type Period from LedgerReports
 * @phpstan-import-type Section from FinancialStatements
 * @phpstan-import-type Pair from FinancialStatements
 * @phpstan-import-type Position from FinancialStatements
 * @phpstan-import-type Activities from FinancialStatements
 * @phpstan-import-type Changes from FinancialStatements
 * @phpstan-import-type CashFlows from FinancialStatements
 */
final class FinancialStatementsExport
{
    private const MONEY_FORMAT = '#,##0;(#,##0);-';

    public function __construct(
        private readonly FinancialStatements $statements,
    ) {}

    /**
     * @param  Period  $period
     */
    public function write(string $tenantId, ?string $scope, array $period, string $entity, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $this->position($writer, $this->statements->position($tenantId, $scope, $period), $entity);
            $writer->addNewSheetAndMakeItCurrent();
            $this->activities($writer, $this->statements->activities($tenantId, $scope, $period), $entity);
            $writer->addNewSheetAndMakeItCurrent();
            $this->changes($writer, $this->statements->netAssetChanges($tenantId, $scope, $period), $period, $entity);
            $writer->addNewSheetAndMakeItCurrent();
            $this->cashFlows($writer, $this->statements->cashFlows($tenantId, $scope, $period), $period, $entity);
        } finally {
            $writer->close();
        }
    }

    /**
     * @param  Position  $report
     */
    private function position(Writer $writer, array $report, string $entity): void
    {
        $this->sheet($writer, 'Posisi keuangan', [50, 18, 18]);
        $this->title($writer, $entity, 'Laporan posisi keuangan per '.LedgerDate::long($report['date']));
        $writer->addRow($this->header(['', LedgerDate::long($report['date']), $report['previous_date'] === null ? '' : LedgerDate::long($report['previous_date'])]));

        $this->bold($writer, 'ASET');
        $this->sections($writer, $report['assets']);
        $this->money($writer, 'Jumlah aset', $report['total_assets'], true);
        $writer->addRow(Row::fromValues(['']));
        $this->bold($writer, 'LIABILITAS');
        $this->sections($writer, $report['liabilities']);
        $this->money($writer, 'Jumlah liabilitas', $report['total_liabilities'], true);
        $writer->addRow(Row::fromValues(['']));
        $this->bold($writer, 'ASET NETO');
        $this->money($writer, 'Aset neto tanpa pembatasan', $report['net_assets']['unrestricted']);
        $this->money($writer, 'Aset neto dengan pembatasan', $report['net_assets']['restricted']);
        $this->money($writer, 'Jumlah aset neto', $report['total_net_assets'], true);
        $previous = $report['total_liabilities']['previous'];
        $this->money($writer, 'JUMLAH LIABILITAS DAN ASET NETO', [
            'current' => $report['total_liabilities']['current'] + $report['total_net_assets']['current'],
            'previous' => $previous === null ? null : $previous + ($report['total_net_assets']['previous'] ?? 0),
        ], true);
    }

    /**
     * @param  Activities  $report
     */
    private function activities(Writer $writer, array $report, string $entity): void
    {
        $this->sheet($writer, 'Penghasilan komprehensif', [50, 18, 18]);
        $this->title($writer, $entity, 'Laporan penghasilan komprehensif '.LedgerDate::long($report['from']).' s.d. '.LedgerDate::long($report['to']));
        $writer->addRow($this->header(['', 'Periode ini', $report['previous_from'] === null ? '' : 'Periode sama tahun lalu']));

        $this->bold($writer, 'TANPA PEMBATASAN DARI PEMBERI SUMBER DAYA');
        $this->bold($writer, 'Pendapatan');
        $this->sections($writer, $report['unrestricted']['revenue']);
        $this->money($writer, 'Aset neto yang dibebaskan dari pembatasan', $report['unrestricted']['released']);
        $this->money($writer, 'Jumlah pendapatan', $report['unrestricted']['revenue_total'], true);
        $this->bold($writer, 'Beban');
        $this->sections($writer, $report['unrestricted']['expenses']);
        $this->money($writer, 'Jumlah beban', $report['unrestricted']['expense_total'], true);
        $this->money($writer, 'Surplus (defisit)', $report['unrestricted']['surplus'], true);
        $writer->addRow(Row::fromValues(['']));
        $this->bold($writer, 'DENGAN PEMBATASAN DARI PEMBERI SUMBER DAYA');
        $this->sections($writer, $report['restricted']['revenue']);
        $this->money($writer, 'Aset neto yang dibebaskan dari pembatasan', $report['restricted']['released']);
        $this->money($writer, 'Kenaikan (penurunan) aset neto dengan pembatasan', $report['restricted']['change'], true);
        $writer->addRow(Row::fromValues(['']));
        $this->money($writer, 'TOTAL PENGHASILAN KOMPREHENSIF', $report['total'], true);
    }

    /**
     * @param  Changes  $report
     * @param  Period  $period
     */
    private function changes(Writer $writer, array $report, array $period, string $entity): void
    {
        $this->sheet($writer, 'Perubahan aset neto', [44, 18, 18, 18]);
        $this->title($writer, $entity, 'Laporan perubahan aset neto '.LedgerDate::long($period['from']).' s.d. '.LedgerDate::long($period['to']));
        $writer->addRow($this->header(['', 'Tanpa pembatasan', 'Dengan pembatasan', 'Jumlah']));

        foreach (['opening' => 'Saldo awal', 'change' => 'Surplus (defisit) / perubahan periode', 'other' => 'Penyesuaian langsung lain', 'closing' => 'Saldo akhir'] as $key => $label) {
            $writer->addRow($this->moneyRow([$label, $report['unrestricted'][$key], $report['restricted'][$key], $report['total'][$key]], $key === 'closing'));
        }
    }

    /**
     * @param  CashFlows  $report
     * @param  Period  $period
     */
    private function cashFlows(Writer $writer, array $report, array $period, string $entity): void
    {
        $this->sheet($writer, 'Arus kas', [50, 18]);
        $this->title($writer, $entity, 'Laporan arus kas (metode langsung) '.LedgerDate::long($period['from']).' s.d. '.LedgerDate::long($period['to']));

        foreach ($report['activities'] as $activity) {
            $this->bold($writer, $activity['label']);

            foreach ($activity['receipts'] as $line) {
                $writer->addRow($this->moneyRow(["Penerimaan — {$line['name']}", $line['amount']]));
            }

            foreach ($activity['payments'] as $line) {
                $writer->addRow($this->moneyRow(["Pengeluaran — {$line['name']}", -$line['amount']]));
            }

            $writer->addRow($this->moneyRow(['Kas bersih', $activity['net']], true));
        }

        $writer->addRow(Row::fromValues(['']));
        $writer->addRow($this->moneyRow(['Kenaikan (penurunan) kas', $report['net_change']], true));

        if ($report['new_accounts'] !== 0) {
            $writer->addRow($this->moneyRow(['Saldo awal rekening kas baru', $report['new_accounts']]));
        }

        $writer->addRow($this->moneyRow(['Kas & bank awal periode', $report['opening']]));
        $writer->addRow($this->moneyRow(['Kas & bank akhir periode', $report['closing']], true));
        $writer->addRow(Row::fromValues(['']));
        $writer->addRow($this->moneyRow(['Kas titipan tabungan siswa (dipisah) — awal', $report['custody']['opening']]));
        $writer->addRow($this->moneyRow(['Kas titipan tabungan siswa (dipisah) — akhir', $report['custody']['closing']]));
    }

    /**
     * @param  list<Section>  $sections
     */
    private function sections(Writer $writer, array $sections): void
    {
        foreach ($sections as $section) {
            $writer->addRow(Row::fromValues(['  '.$section['title']]));

            foreach ($section['lines'] as $line) {
                $this->money($writer, "    {$line['code']} {$line['name']}", $line);
            }
        }
    }

    /**
     * @param  Pair|array{code: string, name: string, current: int, previous: int|null}  $values
     */
    private function money(Writer $writer, string $label, array $values, bool $bold = false): void
    {
        $writer->addRow($this->moneyRow([$label, $values['current'], $values['previous'] ?? ''], $bold));
    }

    /**
     * @param  list<string|int>  $values
     */
    private function moneyRow(array $values, bool $bold = false): Row
    {
        $cells = [];

        foreach ($values as $index => $value) {
            $style = $bold ? (new Style)->withFontBold(true) : new Style;
            $cells[] = Cell::fromValue($value, $index > 0 ? $style->withFormat(self::MONEY_FORMAT) : $style);
        }

        return new Row($cells);
    }

    private function bold(Writer $writer, string $text): void
    {
        $writer->addRow(Row::fromValuesWithStyle([$text], (new Style)->withFontBold(true)));
    }

    private function title(Writer $writer, string $entity, string $title): void
    {
        $writer->addRow(Row::fromValuesWithStyle([$entity], (new Style)->withFontBold(true)));
        $writer->addRow(Row::fromValues([$title]));
    }

    /**
     * @param  list<string>  $titles
     */
    private function header(array $titles): Row
    {
        return Row::fromValuesWithStyle($titles, (new Style)->withFontBold(true)->withBackgroundColor('DDEBF7'));
    }

    /**
     * @param  list<int>  $widths
     */
    private function sheet(Writer $writer, string $name, array $widths): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName($name);

        foreach ($widths as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }
    }
}
