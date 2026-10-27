<?php

namespace Modules\HR\Application\Payroll;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Attendance\AttendanceRecap;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Employee\EmployeeStatus;
use Modules\HR\Domain\Payroll\Exceptions\PayrollException;
use Modules\HR\Domain\Payroll\PayComponentKind;

/**
 * Rekap gaji bulanan (PRD-001 §16.3, HR-5c-1): data rekap (snapshot bila terkunci, hitungan terkini bila
 * belum), penyesuaian manual, kunci & buka kunci bulan. Hak akses (`hr.payroll.manage`) dicek pemanggil.
 *
 * Aturan [ASUMSI disetujui]:
 * - bulan = YYYY-MM, tidak di masa depan; bulan berjalan boleh dikunci (dihitung sampai hari ini);
 * - penyesuaian: tambahan/potongan Rp 1 s.d. 1.000.000.000, keterangan wajib maks. 200; hanya untuk
 *   pegawai aktif dan selama bulan belum dikunci;
 * - buka kunci wajib beralasan (maks. 300); snapshot dihapus, rekap dihitung ulang dari data terkini;
 * - audit tanpa nominal & tanpa keterangan penyesuaian.
 *
 * @phpstan-import-type Payslip from PayrollCalculator
 *
 * @phpstan-type Columns array{position: int, fixed: int, present_day: int, teaching: int, adjustment_earning: int, fixed_deduction: int, attendance_deduction: int, adjustment_deduction: int}
 * @phpstan-type RecapRow array{employee_id: string, name: string, employee_number: string|null, organization: string|null, columns: Columns, earning: int, deduction: int, net: int, lines: list<array{group: string, label: string, detail: string|null, kind: string, amount: int}>, warnings: list<string>, adjustments: list<array{id: string, kind: string, amount: int, note: string}>}
 * @phpstan-type Lock array{locked_at: string, locked_by: string|null, until: string, employee_count: int}
 */
final class ManagePayroll
{
    public const NOTE_MAX = 200;

    public const REASON_MAX = 300;

    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * "2026-10" bila sah dan tidak di masa depan; selain itu bulan berjalan.
     */
    public static function month(mixed $requested): string
    {
        return AttendanceRecap::month($requested);
    }

    /**
     * @return Lock|null
     */
    public function lockInfo(string $tenantId, string $month): ?array
    {
        $row = DB::table('hr_payroll_months as pm')
            ->leftJoin('memberships as m', 'm.id', '=', 'pm.locked_by_membership_id')
            ->leftJoin('persons as p', 'p.id', '=', 'm.person_id')
            ->where('pm.tenant_id', $tenantId)
            ->where('pm.month', $month)
            ->first(['pm.locked_at', 'pm.until', 'pm.employee_count', 'p.name as locked_by']);

        if ($row === null) {
            return null;
        }

        return [
            'locked_at' => CarbonImmutable::parse((string) $row->locked_at)->setTimezone(BusinessClock::timezone())->format('Y-m-d H:i'),
            'locked_by' => $row->locked_by === null ? null : (string) $row->locked_by,
            'until' => (string) $row->until,
            'employee_count' => (int) $row->employee_count,
        ];
    }

    /**
     * Baris rekap: dari snapshot bila bulan terkunci, selain itu dihitung dari data terkini.
     *
     * @return list<RecapRow>
     */
    public function rows(string $tenantId, string $month, ?string $organizationId = null): array
    {
        $locked = $this->lockInfo($tenantId, $month) !== null;
        $payslips = $locked ? $this->snapshots($tenantId, $month, $organizationId) : $this->calculator->compute($tenantId, $month, $organizationId);
        $adjustments = $this->adjustmentRows($tenantId, $month);
        $rows = [];

        foreach ($payslips as $payslip) {
            $rows[] = [
                'employee_id' => $payslip['employee_id'],
                'name' => $payslip['name'],
                'employee_number' => $payslip['employee_number'],
                'organization' => $payslip['organization'],
                'columns' => PayrollCalculator::columns($payslip['lines']),
                'earning' => $payslip['earning'],
                'deduction' => $payslip['deduction'],
                'net' => $payslip['net'],
                'lines' => $payslip['lines'],
                'warnings' => $payslip['warnings'],
                'adjustments' => $adjustments[$payslip['employee_id']] ?? [],
            ];
        }

        return $rows;
    }

    /**
     * Gaji per pegawai untuk unduhan/slip: snapshot bila terkunci, selain itu hitungan terkini.
     *
     * @return list<Payslip>
     */
    public function payslips(string $tenantId, string $month, ?string $organizationId = null): array
    {
        return $this->lockInfo($tenantId, $month) !== null
            ? $this->snapshots($tenantId, $month, $organizationId)
            : $this->calculator->compute($tenantId, $month, $organizationId);
    }

    /**
     * Slip gaji (HR-5c-2): hanya dari bulan yang sudah dikunci; null bila belum dikunci.
     *
     * @return list<Payslip>|null
     */
    public function lockedSlips(string $tenantId, string $month, ?string $organizationId = null, ?string $employeeId = null): ?array
    {
        if ($this->lockInfo($tenantId, $month) === null) {
            return null;
        }

        return $this->snapshots($tenantId, $month, $organizationId, $employeeId);
    }

    /**
     * Bulan terkunci yang punya slip pegawai ini, terbaru dulu (Gaji saya, HR-5c-2).
     *
     * @return list<array{month: string, label: string, net: int, locked_at: string}>
     */
    public function monthsFor(string $tenantId, string $employeeId): array
    {
        $rows = DB::table('hr_payslips as s')
            ->join('hr_payroll_months as pm', 'pm.id', '=', 's.payroll_month_id')
            ->where('pm.tenant_id', $tenantId)
            ->where('s.employee_id', $employeeId)
            ->orderByDesc('pm.month')
            ->limit(60)
            ->get(['pm.month', 'pm.locked_at', 's.net']);

        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'month' => (string) $row->month,
                'label' => AttendanceRecap::monthLabel((string) $row->month),
                'net' => (int) $row->net,
                'locked_at' => CarbonImmutable::parse((string) $row->locked_at)->setTimezone(BusinessClock::timezone())->format('Y-m-d'),
            ];
        }

        return $result;
    }

    /**
     * @throws PayrollException
     */
    public function lock(string $tenantId, string $month, ?string $membershipId): int
    {
        $this->assertMonth($month);

        return DB::transaction(function () use ($tenantId, $month, $membershipId): int {
            // Antrekan penguncian per yayasan agar dua admin tidak mengunci bersamaan.
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();

            if ($this->lockInfo($tenantId, $month) !== null) {
                throw PayrollException::make('form', AttendanceRecap::monthLabel($month).' sudah dikunci.');
            }

            [, $until] = AttendanceRecap::range($month);
            $payslips = $this->calculator->compute($tenantId, $month);
            $monthId = (string) Str::uuid7();
            $now = now();

            DB::table('hr_payroll_months')->insert([
                'id' => $monthId,
                'tenant_id' => $tenantId,
                'month' => $month,
                'until' => $until,
                'employee_count' => count($payslips),
                'earning' => array_sum(array_column($payslips, 'earning')),
                'deduction' => array_sum(array_column($payslips, 'deduction')),
                'net' => array_sum(array_column($payslips, 'net')),
                'locked_at' => $now,
                'locked_by_membership_id' => $membershipId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (array_chunk($payslips, 200) as $chunk) {
                DB::table('hr_payslips')->insert(array_map(fn (array $payslip): array => [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'payroll_month_id' => $monthId,
                    'employee_id' => $payslip['employee_id'],
                    'employee_name' => $payslip['name'],
                    'employee_number' => $payslip['employee_number'],
                    'organization_id' => $payslip['organization_id'],
                    'organization' => $payslip['organization'],
                    'earning' => $payslip['earning'],
                    'deduction' => $payslip['deduction'],
                    'net' => $payslip['net'],
                    'lines' => json_encode($payslip['lines'], JSON_THROW_ON_ERROR),
                    'basis' => json_encode(['basis' => $payslip['basis'], 'warnings' => $payslip['warnings']], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            $this->audit->record(
                HrAuditAction::PAYROLL_LOCKED,
                HrAuditAction::SUBJECT_PAYROLL_MONTH,
                $monthId,
                ['bulan' => $month, 'sampai' => $until, 'pegawai' => count($payslips)],
                tenantId: $tenantId,
            );

            return count($payslips);
        });
    }

    /**
     * @throws PayrollException
     */
    public function unlock(string $tenantId, string $month, string $reason): void
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw PayrollException::make('reason', 'Tulis alasan buka kunci, maksimal '.self::REASON_MAX.' karakter.');
        }

        DB::transaction(function () use ($tenantId, $month, $reason): void {
            $row = DB::table('hr_payroll_months')->where('tenant_id', $tenantId)->where('month', $month)->lockForUpdate()->first();

            if ($row === null) {
                throw PayrollException::make('form', AttendanceRecap::monthLabel($month).' belum dikunci.');
            }

            DB::table('hr_payroll_months')->where('id', $row->id)->delete();

            $this->audit->record(
                HrAuditAction::PAYROLL_UNLOCKED,
                HrAuditAction::SUBJECT_PAYROLL_MONTH,
                (string) $row->id,
                ['bulan' => $month, 'alasan' => $reason],
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @throws PayrollException
     */
    public function addAdjustment(string $tenantId, string $month, string $employeeId, PayComponentKind $kind, mixed $amount, string $note, ?string $membershipId): void
    {
        $this->assertMonth($month);
        $value = EmployeePay::parseAmount($amount, 'amount', 'penyesuaian');

        if ($value === null || $value < 1) {
            throw PayrollException::make('amount', 'Isi nominal penyesuaian (minimal Rp 1).');
        }

        $note = trim((string) preg_replace('/\s+/u', ' ', $note));

        if ($note === '' || mb_strlen($note) > self::NOTE_MAX) {
            throw PayrollException::make('note', 'Tulis keterangan penyesuaian, maksimal '.self::NOTE_MAX.' karakter.');
        }

        $active = Str::isUuid($employeeId) && DB::table('hr_employees')->where('tenant_id', $tenantId)->where('id', $employeeId)->where('status', EmployeeStatus::Active->value)->exists();

        if (! $active) {
            throw PayrollException::make('form', 'Pegawai tidak ditemukan atau sudah berhenti.');
        }

        DB::transaction(function () use ($tenantId, $month, $employeeId, $kind, $value, $note, $membershipId): void {
            $this->assertOpen($tenantId, $month);
            $id = (string) Str::uuid7();

            DB::table('hr_payroll_adjustments')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'month' => $month,
                'employee_id' => $employeeId,
                'kind' => $kind->value,
                'amount' => $value,
                'note' => $note,
                'created_by_membership_id' => $membershipId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                HrAuditAction::PAYROLL_ADJUSTED,
                HrAuditAction::SUBJECT_EMPLOYEE,
                $employeeId,
                ['bulan' => $month, 'jenis' => $kind->value, 'aksi' => 'tambah'],
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @throws PayrollException
     */
    public function removeAdjustment(string $tenantId, string $month, string $adjustmentId): void
    {
        DB::transaction(function () use ($tenantId, $month, $adjustmentId): void {
            $this->assertOpen($tenantId, $month);

            $row = Str::isUuid($adjustmentId)
                ? DB::table('hr_payroll_adjustments')->where('tenant_id', $tenantId)->where('month', $month)->where('id', $adjustmentId)->first()
                : null;

            if ($row === null) {
                throw PayrollException::make('form', 'Penyesuaian tidak ditemukan.');
            }

            DB::table('hr_payroll_adjustments')->where('id', $adjustmentId)->delete();

            $this->audit->record(
                HrAuditAction::PAYROLL_ADJUSTED,
                HrAuditAction::SUBJECT_EMPLOYEE,
                (string) $row->employee_id,
                ['bulan' => $month, 'jenis' => (string) $row->kind, 'aksi' => 'hapus'],
                tenantId: $tenantId,
            );
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @throws PayrollException
     */
    private function assertMonth(string $month): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1 || $month > BusinessClock::today()->format('Y-m')) {
            throw PayrollException::make('form', 'Bulan tidak valid atau masih di masa depan.');
        }
    }

    /**
     * @throws PayrollException
     */
    private function assertOpen(string $tenantId, string $month): void
    {
        if (DB::table('hr_payroll_months')->where('tenant_id', $tenantId)->where('month', $month)->exists()) {
            throw PayrollException::make('form', AttendanceRecap::monthLabel($month).' sudah dikunci. Buka kunci dulu untuk mengubah penyesuaian.');
        }
    }

    /**
     * @return array<string, list<array{id: string, kind: string, amount: int, note: string}>>
     */
    private function adjustmentRows(string $tenantId, string $month): array
    {
        $result = [];

        foreach (DB::table('hr_payroll_adjustments')->where('tenant_id', $tenantId)->where('month', $month)->orderBy('created_at')->orderBy('id')->get() as $row) {
            $result[(string) $row->employee_id][] = ['id' => (string) $row->id, 'kind' => (string) $row->kind, 'amount' => (int) $row->amount, 'note' => (string) $row->note];
        }

        return $result;
    }

    /**
     * @return list<Payslip>
     */
    private function snapshots(string $tenantId, string $month, ?string $organizationId, ?string $employeeId = null): array
    {
        $query = DB::table('hr_payslips as s')
            ->join('hr_payroll_months as pm', 'pm.id', '=', 's.payroll_month_id')
            ->where('pm.tenant_id', $tenantId)
            ->where('pm.month', $month);

        if ($employeeId !== null) {
            $query->where('s.employee_id', $employeeId);
        }

        if ($organizationId !== null) {
            $query->whereIn('s.organization_id', fn ($closure) => $closure->select('descendant_id')->from('organization_closure')->where('ancestor_id', $organizationId));
        }

        $result = [];

        foreach ($query->orderBy('s.employee_name')->orderBy('s.employee_id')->get(['s.*']) as $row) {
            $extra = json_decode((string) $row->basis, true, flags: JSON_THROW_ON_ERROR);
            $lines = json_decode((string) $row->lines, true, flags: JSON_THROW_ON_ERROR);

            $result[] = [
                'employee_id' => (string) $row->employee_id,
                'name' => (string) $row->employee_name,
                'employee_number' => $row->employee_number === null ? null : (string) $row->employee_number,
                'organization_id' => $row->organization_id === null ? null : (string) $row->organization_id,
                'organization' => $row->organization === null ? null : (string) $row->organization,
                'lines' => self::decodeLines($lines),
                'basis' => self::decodeBasis(is_array($extra) ? ($extra['basis'] ?? null) : null),
                'warnings' => self::decodeWarnings(is_array($extra) ? ($extra['warnings'] ?? null) : null),
                'earning' => (int) $row->earning,
                'deduction' => (int) $row->deduction,
                'net' => (int) $row->net,
            ];
        }

        return $result;
    }

    /**
     * Baris rincian dari snapshot JSON, dengan bentuk yang dijamin.
     *
     * @return list<array{group: string, label: string, detail: string|null, kind: string, amount: int}>
     */
    private static function decodeLines(mixed $value): array
    {
        $lines = [];

        foreach (is_array($value) ? $value : [] as $line) {
            if (! is_array($line)) {
                continue;
            }

            $lines[] = [
                'group' => is_string($line['group'] ?? null) ? $line['group'] : '',
                'label' => is_string($line['label'] ?? null) ? $line['label'] : '',
                'detail' => is_string($line['detail'] ?? null) ? $line['detail'] : null,
                'kind' => ($line['kind'] ?? null) === 'POTONGAN' ? 'POTONGAN' : 'PENDAPATAN',
                'amount' => is_int($line['amount'] ?? null) ? $line['amount'] : 0,
            ];
        }

        return $lines;
    }

    /**
     * @return array{present_days: int, absent: int, late: int, early: int, empty: int, teaching: list<array{organization: string, mode: string, jp: int, rate: int, amount: int, irregular: int}>}
     */
    private static function decodeBasis(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $int = fn (mixed $number): int => is_int($number) ? $number : 0;
        $teaching = [];

        foreach (is_array($value['teaching'] ?? null) ? $value['teaching'] : [] as $line) {
            if (! is_array($line)) {
                continue;
            }

            $teaching[] = [
                'organization' => is_string($line['organization'] ?? null) ? $line['organization'] : '',
                'mode' => is_string($line['mode'] ?? null) ? $line['mode'] : '',
                'jp' => $int($line['jp'] ?? null),
                'rate' => $int($line['rate'] ?? null),
                'amount' => $int($line['amount'] ?? null),
                'irregular' => $int($line['irregular'] ?? null),
            ];
        }

        return [
            'present_days' => $int($value['present_days'] ?? null),
            'absent' => $int($value['absent'] ?? null),
            'late' => $int($value['late'] ?? null),
            'early' => $int($value['early'] ?? null),
            'empty' => $int($value['empty'] ?? null),
            'teaching' => $teaching,
        ];
    }

    /**
     * @return list<string>
     */
    private static function decodeWarnings(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], 'is_string'));
    }
}
