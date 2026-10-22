<?php

namespace Modules\HR\Application\Leave;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\HR\Domain\Leave\LeaveStatus;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Rekap cuti .xlsx (PRD-001 §13.5, HR-3d-b) — dua lembar [OWNER, 2026-10-22]:
 *
 * - "Daftar cuti": cuti DISETUJUI yang jatuh di periode (setahun atau satu bulan) [ASUMSI], satu
 *   baris per cuti, tanpa alasan.
 * - "Saldo": pegawai aktif × jenis berjatah untuk tahun itu.
 *
 * Saringan lembaga (termasuk bawahannya) & jenis berlaku untuk keduanya. Hak akses dicek pemanggil.
 *
 * @phpstan-import-type Filters from LeaveBalanceSheet
 */
final class LeaveRecapExport
{
    private const TEXT_FORMAT = '@';

    public function __construct(
        private readonly LeaveBalanceSheet $sheet,
    ) {}

    public static function fileName(int $year, ?int $month): string
    {
        return 'rekap-cuti-'.$year.($month === null ? '' : '-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT)).'.xlsx';
    }

    /**
     * @param  Filters  $filters
     */
    public function write(string $tenantId, array $filters, ?int $month, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        try {
            $this->leaveSheet($writer, $tenantId, $filters, $month);
            $writer->addNewSheetAndMakeItCurrent();
            $this->balanceSheet($writer, $tenantId, $filters);
            $writer->setCurrentSheet($writer->getSheets()[0]);
        } finally {
            $writer->close();
        }
    }

    /**
     * @param  Filters  $filters
     */
    private function leaveSheet(Writer $writer, string $tenantId, array $filters, ?int $month): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Daftar cuti');
        $sheet->setSheetView(new SheetView(freezeRow: 2));

        foreach ([5, 30, 16, 28, 22, 12, 12, 10, 24] as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $writer->addRow($this->header(['No', 'Nama', 'Nomor induk', 'Lembaga', 'Jenis', 'Mulai', 'Selesai', 'Hari kerja', 'Dicatat oleh']));
        $text = (new Style)->withFormat(self::TEXT_FORMAT);

        $first = CarbonImmutable::parse(sprintf('%04d-%02d-01', $filters['tahun'], $month ?? 1));
        $last = $month === null ? $first->endOfYear() : $first->endOfMonth();

        $query = DB::table('hr_leave_requests as r')
            ->join('hr_employees as e', 'e.id', '=', 'r.employee_id')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->join('hr_leave_types as t', 't.id', '=', 'r.leave_type_id')
            ->join('organizations as o', 'o.id', '=', 'r.organization_id')
            ->leftJoin('memberships as rm', 'rm.id', '=', 'r.recorded_by_membership_id')
            ->leftJoin('persons as rp', 'rp.id', '=', 'rm.person_id')
            ->where('r.tenant_id', $tenantId)
            ->where('r.status', LeaveStatus::Approved->value)
            ->where('r.start_date', '<=', $last->toDateString())
            ->where('r.end_date', '>=', $first->toDateString());

        if ($filters['lembaga'] !== '') {
            $query->whereIn('r.organization_id', fn (Builder $sub) => $sub
                ->select('descendant_id')
                ->from('organization_closure')
                ->where('tenant_id', $tenantId)
                ->where('ancestor_id', $filters['lembaga']));
        }

        if ($filters['jenis'] !== '') {
            $query->where('r.leave_type_id', $filters['jenis']);
        }

        $rows = $query
            ->orderBy('r.start_date')
            ->orderBy('p.name')
            ->orderBy('r.id')
            ->get(['p.name', 'e.employee_number', 'o.name as organization', 't.name as type', 'r.start_date', 'r.end_date', 'r.days', 'rp.name as recorded_by']);

        foreach ($rows as $index => $row) {
            $writer->addRow(new Row([
                Cell::fromValue($index + 1),
                Cell::fromValue((string) $row->name),
                Cell::fromValue($row->employee_number === null ? '' : (string) $row->employee_number, $text),
                Cell::fromValue((string) $row->organization),
                Cell::fromValue((string) $row->type),
                Cell::fromValue(substr((string) $row->start_date, 0, 10)),
                Cell::fromValue(substr((string) $row->end_date, 0, 10)),
                Cell::fromValue((int) $row->days),
                Cell::fromValue($row->recorded_by === null ? 'Pegawai sendiri' : (string) $row->recorded_by),
            ]));
        }
    }

    /**
     * @param  Filters  $filters
     */
    private function balanceSheet(Writer $writer, string $tenantId, array $filters): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Saldo '.$filters['tahun']);
        $sheet->setSheetView(new SheetView(freezeRow: 2));

        foreach ([5, 30, 16, 28, 22, 10, 12, 10, 10, 10] as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $writer->addRow($this->header(['No', 'Nama', 'Nomor induk', 'Lembaga', 'Jenis', 'Jatah', 'Penyesuaian', 'Terpakai', 'Menunggu', 'Sisa']));
        $text = (new Style)->withFormat(self::TEXT_FORMAT);
        $number = 0;

        foreach ($this->sheet->all($tenantId, $filters) as $employee) {
            foreach ($employee['balances'] as $balance) {
                $writer->addRow(new Row([
                    Cell::fromValue(++$number),
                    Cell::fromValue($employee['name']),
                    Cell::fromValue($employee['employee_number'] ?? '', $text),
                    Cell::fromValue($employee['organization'] ?? ''),
                    Cell::fromValue($balance['name']),
                    Cell::fromValue($balance['quota']),
                    Cell::fromValue($balance['adjustment']),
                    Cell::fromValue($balance['used']),
                    Cell::fromValue($balance['pending']),
                    Cell::fromValue($balance['remaining']),
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
