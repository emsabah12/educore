<?php

namespace Modules\Finance\Application\Savings;

use Modules\Finance\Application\Report\Arrears;
use Modules\Finance\Domain\Payment\PaymentStatus;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Unduhan tabungan .xlsx satu lembaga (PRD-005 §6, TB-2):
 * - daftar saldo (lembar "Saldo"), saringan kelas / cari / saldo > 0 ikut berlaku;
 * - rekap bulanan (lembar "Ringkasan" & "Transaksi", termasuk transaksi batal beserta alasannya).
 * Hak akses dicek pemanggil.
 */
final class SavingsExport
{
    private const TEXT_FORMAT = '@';

    private const MONEY_FORMAT = '#,##0';

    public function __construct(
        private readonly SavingsReport $report,
    ) {}

    public static function balancesFileName(string $organizationCode, string $today): string
    {
        return 'saldo-tabungan-'.self::code($organizationCode).'-'.$today.'.xlsx';
    }

    public static function monthlyFileName(string $organizationCode, string $month): string
    {
        return 'rekap-tabungan-'.self::code($organizationCode).'-'.$month.'.xlsx';
    }

    public function writeBalances(string $tenantId, string $organizationId, string $organizationName, string $today, ?string $group, ?string $search, bool $positiveOnly, string $path): void
    {
        $data = $this->report->balances($tenantId, $organizationId, $group, $search, $positiveOnly);
        $title = "Saldo tabungan {$organizationName} per {$today}".match ($group) {
            null => '',
            Arrears::NO_CLASS, Arrears::LEFT => ' — '.mb_strtolower(Arrears::groupLabel($group)),
            default => ' — kelas '.$group,
        };

        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Saldo');
            $sheet->setSheetView(new SheetView(freezeRow: 3));

            foreach ([5, 30, 14, 14, 12, 14, 16, 18] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }

            $writer->addRow(Row::fromValues([$title]));
            $writer->addRow($this->header(['No', 'Nama', 'NIS', 'NISN', 'Kelas', 'Status', 'Saldo (Rp)', 'Transaksi terakhir']));
            $text = (new Style)->withFormat(self::TEXT_FORMAT);
            $money = (new Style)->withFormat(self::MONEY_FORMAT);

            foreach ($data['students'] as $index => $student) {
                $writer->addRow(new Row([
                    Cell::fromValue($index + 1),
                    Cell::fromValue($student['name']),
                    Cell::fromValue($student['student_number'] ?? '', $text),
                    Cell::fromValue($student['nisn'] ?? '', $text),
                    Cell::fromValue($student['class'] ?? ''),
                    Cell::fromValue($student['active'] ? 'Aktif' : 'Sudah keluar'),
                    Cell::fromValue($student['balance'], $money),
                    Cell::fromValue($student['last_date']),
                ]));
            }

            $writer->addRow($this->totalRow(['', 'Total', '', '', '', '', $data['total'], ''], 6));
        } finally {
            $writer->close();
        }
    }

    public function writeMonthly(string $tenantId, string $organizationId, string $organizationName, string $month, string $monthLabel, string $path): void
    {
        $data = $this->report->monthly($tenantId, $organizationId, $month);
        $title = "Rekap tabungan {$organizationName} — {$monthLabel}";
        $money = (new Style)->withFormat(self::MONEY_FORMAT);

        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Ringkasan');
            $sheet->setColumnWidth(28, 1);
            $sheet->setColumnWidth(12, 2);
            $sheet->setColumnWidth(18, 3);

            $writer->addRow(Row::fromValues([$title]));
            $writer->addRow($this->header(['Uraian', 'Transaksi', 'Jumlah (Rp)']));

            foreach ([
                ['Saldo awal bulan', '', $data['opening']],
                ['Setoran', $data['deposit_count'], $data['deposits']],
                ['Penarikan', $data['withdrawal_count'], $data['withdrawals']],
                ['Bayar tagihan', $data['payment_count'], $data['payments']],
            ] as [$label, $count, $amount]) {
                $writer->addRow(new Row([Cell::fromValue($label), Cell::fromValue($count), Cell::fromValue($amount, $money)]));
            }

            $writer->addRow($this->totalRow(['Saldo akhir bulan', '', $data['closing']], 2));

            if ($data['voided_count'] > 0) {
                $writer->addRow(Row::fromValues(["{$data['voided_count']} transaksi dibatalkan tidak dihitung (lihat lembar Transaksi)."]));
            }

            $writer->addNewSheetAndMakeItCurrent();
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Transaksi');
            $sheet->setSheetView(new SheetView(freezeRow: 3));

            foreach ([5, 12, 26, 28, 10, 14, 14, 16, 22, 22, 10, 28] as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }

            $writer->addRow(Row::fromValues([$title]));
            $writer->addRow($this->header(['No', 'Tanggal', 'Nomor slip', 'Nama', 'Kelas', 'Jenis', 'Cara', 'Jumlah (Rp)', 'Diambil oleh', 'Petugas', 'Status', 'Alasan batal']));

            foreach ($data['entries'] as $index => $entry) {
                $writer->addRow(new Row([
                    Cell::fromValue($index + 1),
                    Cell::fromValue($entry['entry_date']),
                    Cell::fromValue($entry['slip_number']),
                    Cell::fromValue($entry['name']),
                    Cell::fromValue($entry['class'] ?? ''),
                    Cell::fromValue($entry['kind_label']),
                    Cell::fromValue($entry['method'] ?? ''),
                    Cell::fromValue($entry['amount'], $money),
                    Cell::fromValue($entry['taken_by'] ?? ''),
                    Cell::fromValue($entry['recorded_by'] ?? ''),
                    Cell::fromValue($entry['status'] === PaymentStatus::Valid->value ? 'Sah' : 'Batal'),
                    Cell::fromValue($entry['void_reason'] ?? ''),
                ]));
            }

            $writer->setCurrentSheet($writer->getSheets()[0]);
        } finally {
            $writer->close();
        }
    }

    private static function code(string $organizationCode): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $organizationCode) ?? 'lembaga');
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
