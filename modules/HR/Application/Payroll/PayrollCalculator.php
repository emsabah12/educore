<?php

namespace Modules\HR\Application\Payroll;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\HR\Domain\Employee\EmployeeStatus;

/**
 * Gaji satu bulan per pegawai, dihitung dari data terkini (PRD-001 §16.3, HR-5c-1): dasar rekap yang
 * belum dikunci dan isi snapshot saat dikunci. Hanya menghitung; hak akses dicek pemanggil.
 *
 * Baris (`lines`) per kelompok:
 * - JABATAN — komponen Menurut jabatan, satu baris per jabatan yang dipegang;
 * - TETAP — komponen Tetap per bulan;
 * - HARI_HADIR — komponen Per hari hadir × (Hadir + Dinas luar);
 * - HONOR_JP — honor mengajar per lembaga (hanya penerima honor JP dengan JP > 0);
 * - POTONGAN_PRESENSI — Alpa/Terlambat/Pulang cepat × tarif;
 * - PENYESUAIAN — penyesuaian manual bulan itu.
 *
 * Pegawai = pegawai AKTIF saat dihitung [ASUMSI]; pegawai yang berhenti dibayar lewat penyesuaian.
 *
 * @phpstan-type PayLine array{group: string, label: string, detail: string|null, kind: string, amount: int}
 * @phpstan-type Payslip array{
 *     employee_id: string,
 *     name: string,
 *     employee_number: string|null,
 *     organization_id: string|null,
 *     organization: string|null,
 *     lines: list<PayLine>,
 *     basis: array{present_days: int, absent: int, late: int, early: int, empty: int, teaching: list<array{organization: string, mode: string, jp: int, rate: int, amount: int, irregular: int}>},
 *     warnings: list<string>,
 *     earning: int,
 *     deduction: int,
 *     net: int
 * }
 */
final class PayrollCalculator
{
    public const GROUPS = ['JABATAN', 'TETAP', 'HARI_HADIR', 'HONOR_JP', 'POTONGAN_PRESENSI', 'PENYESUAIAN'];

    public function __construct(
        private readonly EmployeePay $pay,
        private readonly VariablePay $variable,
    ) {}

    /**
     * @return list<Payslip> urut nama
     */
    public function compute(string $tenantId, string $month, ?string $organizationId = null): array
    {
        $employees = $this->employees($tenantId, $organizationId);

        if ($employees === []) {
            return [];
        }

        $ids = array_keys($employees);
        $summaries = $this->pay->summaries($tenantId, $ids);
        $teaching = $this->variable->teaching($tenantId, $ids, $month);
        $adjustments = $this->adjustments($tenantId, $month, $ids);
        $result = [];

        foreach ($employees as $employeeId => $employee) {
            $summary = $summaries[$employeeId];
            $attendance = $this->variable->attendance($tenantId, $employeeId, $month);
            $presentDays = $attendance['present_days'] ?? 0;
            $lines = [];
            $warnings = [];

            foreach ($summary['positions'] as $line) {
                $lines[] = ['group' => 'JABATAN', 'label' => $line['component'], 'detail' => "{$line['position']} — {$line['organization']}", 'kind' => $line['kind'], 'amount' => $line['amount']];
            }

            foreach ($summary['rows'] as $row) {
                if (! $row['is_active'] || $row['amount'] === null) {
                    continue;
                }

                if ($row['basis'] === 'PER_HARI_HADIR') {
                    $amount = $row['amount'] * $presentDays;

                    if ($amount > 0) {
                        $lines[] = ['group' => 'HARI_HADIR', 'label' => $row['name'], 'detail' => self::rupiah($row['amount'])." × {$presentDays} hari", 'kind' => $row['kind'], 'amount' => $amount];
                    }

                    continue;
                }

                $lines[] = ['group' => 'TETAP', 'label' => $row['name'], 'detail' => null, 'kind' => $row['kind'], 'amount' => $row['amount']];
            }

            $teachingBasis = [];

            foreach ($teaching[$employeeId]['lines'] as $line) {
                $teachingBasis[] = ['organization' => $line['organization'], 'mode' => $line['mode'], 'jp' => $line['jp'], 'rate' => $line['rate'], 'amount' => $line['amount'], 'irregular' => $line['irregular']];

                if ($line['irregular'] > 0) {
                    $warnings[] = "{$line['irregular']} jadwal/pertemuan di {$line['organization']} tidak pas kelipatan JP.";
                }

                if ($line['amount'] > 0) {
                    $lines[] = ['group' => 'HONOR_JP', 'label' => "Honor mengajar — {$line['organization']}", 'detail' => "{$line['mode_label']}: {$line['jp']} JP × ".self::rupiah($line['rate']), 'kind' => 'PENDAPATAN', 'amount' => $line['amount']];
                }
            }

            if ($attendance !== null && $attendance['deduction'] > 0) {
                $parts = [];

                foreach (['absent' => 'Alpa', 'late' => 'Terlambat', 'early' => 'Pulang cepat'] as $field => $label) {
                    if ($attendance[$field] > 0 && $attendance['rates'][$field] > 0) {
                        $parts[] = "{$label} {$attendance[$field]} × ".self::rupiah($attendance['rates'][$field]);
                    }
                }

                $lines[] = ['group' => 'POTONGAN_PRESENSI', 'label' => 'Potongan presensi', 'detail' => implode('; ', $parts), 'kind' => 'POTONGAN', 'amount' => $attendance['deduction']];
            }

            if ($attendance === null) {
                $warnings[] = 'Tanpa penempatan utama: presensi tidak dihitung.';
            } elseif ($attendance['empty'] > 0) {
                $warnings[] = "{$attendance['empty']} hari wajib belum diisi presensinya.";
            }

            foreach ($adjustments[$employeeId] ?? [] as $adjustment) {
                $lines[] = ['group' => 'PENYESUAIAN', 'label' => $adjustment['note'], 'detail' => null, 'kind' => $adjustment['kind'], 'amount' => $adjustment['amount']];
            }

            if ($lines === []) {
                $warnings[] = 'Belum ada komponen gaji.';
            }

            $earning = 0;
            $deduction = 0;

            foreach ($lines as $line) {
                if ($line['kind'] === 'POTONGAN') {
                    $deduction += $line['amount'];
                } else {
                    $earning += $line['amount'];
                }
            }

            $result[] = [
                'employee_id' => $employeeId,
                'name' => $employee['name'],
                'employee_number' => $employee['employee_number'],
                'organization_id' => $employee['organization_id'],
                'organization' => $employee['organization'],
                'lines' => $lines,
                'basis' => [
                    'present_days' => $presentDays,
                    'absent' => $attendance['absent'] ?? 0,
                    'late' => $attendance['late'] ?? 0,
                    'early' => $attendance['early'] ?? 0,
                    'empty' => $attendance['empty'] ?? 0,
                    'teaching' => $teachingBasis,
                ],
                'warnings' => $warnings,
                'earning' => $earning,
                'deduction' => $deduction,
                'net' => $earning - $deduction,
            ];
        }

        return $result;
    }

    /**
     * Jumlah per kelompok rekap, dari baris rincian.
     *
     * @param  list<PayLine>  $lines
     * @return array{position: int, fixed: int, present_day: int, teaching: int, adjustment_earning: int, fixed_deduction: int, attendance_deduction: int, adjustment_deduction: int}
     */
    public static function columns(array $lines): array
    {
        $columns = ['position' => 0, 'fixed' => 0, 'present_day' => 0, 'teaching' => 0, 'adjustment_earning' => 0, 'fixed_deduction' => 0, 'attendance_deduction' => 0, 'adjustment_deduction' => 0];

        foreach ($lines as $line) {
            $deduction = $line['kind'] === 'POTONGAN';
            $key = match (true) {
                $line['group'] === 'PENYESUAIAN' => $deduction ? 'adjustment_deduction' : 'adjustment_earning',
                $line['group'] === 'POTONGAN_PRESENSI' => 'attendance_deduction',
                $deduction => 'fixed_deduction',
                $line['group'] === 'JABATAN' => 'position',
                $line['group'] === 'HARI_HADIR' => 'present_day',
                $line['group'] === 'HONOR_JP' => 'teaching',
                default => 'fixed',
            };
            $columns[$key] += $line['amount'];
        }

        return $columns;
    }

    public static function rupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }

    /**
     * Pegawai aktif (saring lembaga penempatan utama beserta turunannya), urut nama.
     *
     * @return array<string, array{name: string, employee_number: string|null, organization_id: string|null, organization: string|null}>
     */
    private function employees(string $tenantId, ?string $organizationId): array
    {
        $query = DB::table('hr_employees as e')
            ->join('memberships as m', 'm.id', '=', 'e.membership_id')
            ->join('persons as p', 'p.id', '=', 'm.person_id')
            ->leftJoin('hr_placements as pl', fn ($join) => $join->on('pl.employee_id', '=', 'e.id')->where('pl.is_primary', true)->whereNull('pl.end_date'))
            ->leftJoin('organizations as o', 'o.id', '=', 'pl.organization_id')
            ->where('e.tenant_id', $tenantId)
            ->where('e.status', EmployeeStatus::Active->value);

        if ($organizationId !== null) {
            $query->whereIn('pl.organization_id', fn (Builder $closure) => $closure->select('descendant_id')->from('organization_closure')->where('ancestor_id', $organizationId));
        }

        $result = [];

        foreach ($query->orderBy('p.name')->orderBy('e.id')->get(['e.id', 'e.employee_number', 'p.name', 'pl.organization_id', 'o.name as organization']) as $row) {
            $result[(string) $row->id] = [
                'name' => (string) $row->name,
                'employee_number' => $row->employee_number === null ? null : (string) $row->employee_number,
                'organization_id' => $row->organization_id === null ? null : (string) $row->organization_id,
                'organization' => $row->organization === null ? null : (string) $row->organization,
            ];
        }

        return $result;
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, list<array{kind: string, amount: int, note: string}>>
     */
    private function adjustments(string $tenantId, string $month, array $employeeIds): array
    {
        $result = [];

        foreach (DB::table('hr_payroll_adjustments')->where('tenant_id', $tenantId)->where('month', $month)->whereIn('employee_id', $employeeIds)->orderBy('created_at')->orderBy('id')->get() as $row) {
            $result[(string) $row->employee_id][] = ['kind' => (string) $row->kind, 'amount' => (int) $row->amount, 'note' => (string) $row->note];
        }

        return $result;
    }
}
