<?php

namespace Modules\HR\Application\Payroll;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Employee\Employee;
use Modules\HR\Domain\Payroll\EmployeePayComponent;
use Modules\HR\Domain\Payroll\Exceptions\PayrollException;
use Modules\HR\Domain\Payroll\PayComponent;
use Modules\HR\Domain\Payroll\PayComponentBasis;
use Modules\HR\Domain\Payroll\PayComponentKind;
use Modules\HR\Domain\Payroll\PayPositionRate;

/**
 * Nominal komponen gaji per pegawai (PRD-001 §16.1, HR-5a). Hak akses (`hr.payroll.manage`) dicek pemanggil.
 *
 * - Nominal rupiah bulat 0 s.d. Rp 1.000.000.000; boleh ditulis "2.500.000" atau "Rp 2.500.000".
 * - Kosong = komponen tidak berlaku untuk pegawai ini (baris dihapus).
 * - Hanya komponen aktif bercara hitung Tetap / Per hari hadir yang bisa diisi; nominal komponen
 *   nonaktif tetap tersimpan apa adanya. Komponen Menurut jabatan memakai tarif jabatan (HR-5a-2).
 * - Audit hanya mencatat kode komponen yang berubah, bukan nominalnya (PRD-001 §4.5).
 *
 * @phpstan-type PayRow array{
 *     component_id: string,
 *     code: string,
 *     name: string,
 *     kind: string,
 *     kind_label: string,
 *     basis: string,
 *     basis_label: string,
 *     is_active: bool,
 *     amount: int|null
 * }
 * @phpstan-type PositionLine array{
 *     component_id: string,
 *     component: string,
 *     kind: string,
 *     position: string,
 *     organization: string,
 *     amount: int,
 *     scope: 'LEMBAGA'|'SEMUA'
 * }
 * @phpstan-type PaySummary array{
 *     rows: list<PayRow>,
 *     placements: list<array{position: string, organization: string, is_primary: bool}>,
 *     positions: list<PositionLine>,
 *     monthly_earning: int,
 *     monthly_deduction: int,
 *     per_present_day: int,
 *     configured: bool
 * }
 */
final class EmployeePay
{
    public const MAX_AMOUNT = 1_000_000_000;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Ringkasan gaji tetap satu pegawai. Lihat summaries().
     *
     * @return PaySummary
     */
    public function forEmployee(string $tenantId, string $employeeId): array
    {
        return $this->summaries($tenantId, [$employeeId])[$employeeId];
    }

    /**
     * Ringkasan gaji tetap beberapa pegawai sekaligus (daftar Gaji pegawai, rekap):
     *
     * - `rows`: komponen per pegawai (Tetap & Per hari hadir) yang aktif — terisi atau belum — ditambah
     *   komponen nonaktif yang masih punya nominal; pendapatan dulu, lalu potongan;
     * - `placements`: jabatan yang sedang dipegang (penempatan berjalan);
     * - `positions`: baris komponen Menurut jabatan untuk setiap jabatan yang punya tarif (tarif
     *   lembaga penempatan didahulukan, lalu tarif semua lembaga) — dijumlahkan bila rangkap jabatan;
     * - total hanya dari komponen aktif.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, PaySummary>
     */
    public function summaries(string $tenantId, array $employeeIds): array
    {
        $result = [];

        if ($employeeIds === []) {
            return $result;
        }

        $components = $this->components($tenantId);
        $amounts = [];

        foreach (EmployeePayComponent::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereIn('employee_id', $employeeIds)->get() as $row) {
            $amounts[$row->employee_id][$row->pay_component_id] = $row->amount;
        }

        $placements = $this->placements($tenantId, $employeeIds);
        $rates = $this->rates($tenantId);

        foreach ($employeeIds as $employeeId) {
            $rows = [];
            $positionLines = [];
            $earning = 0;
            $deduction = 0;
            $perDay = 0;

            foreach ($components as $component) {
                if ($component->basis === PayComponentBasis::PerPosition) {
                    if (! $component->is_active) {
                        continue;
                    }

                    foreach ($placements[$employeeId] ?? [] as $placement) {
                        $rate = $rates[$component->id][$placement['position_id']][$placement['organization_id']]
                            ?? $rates[$component->id][$placement['position_id']]['']
                            ?? null;

                        if ($rate === null) {
                            continue;
                        }

                        $positionLines[] = [
                            'component_id' => $component->id,
                            'component' => $component->name,
                            'kind' => $component->kind->value,
                            'position' => $placement['position'],
                            'organization' => $placement['organization'],
                            'amount' => $rate['amount'],
                            'scope' => $rate['scope'],
                        ];

                        if ($component->kind === PayComponentKind::Earning) {
                            $earning += $rate['amount'];
                        } else {
                            $deduction += $rate['amount'];
                        }
                    }

                    continue;
                }

                $amount = $amounts[$employeeId][$component->id] ?? null;

                if (! $component->is_active && $amount === null) {
                    continue;
                }

                $rows[] = [
                    'component_id' => $component->id,
                    'code' => $component->code,
                    'name' => $component->name,
                    'kind' => $component->kind->value,
                    'kind_label' => $component->kind->label(),
                    'basis' => $component->basis->value,
                    'basis_label' => $component->basis->label(),
                    'is_active' => $component->is_active,
                    'amount' => $amount,
                ];

                // Ringkasan hanya dari komponen aktif (yang nonaktif tidak ikut rekap baru).
                if ($amount === null || ! $component->is_active) {
                    continue;
                }

                if ($component->basis === PayComponentBasis::PerPresentDay) {
                    $perDay += $component->kind === PayComponentKind::Earning ? $amount : -$amount;
                } elseif ($component->kind === PayComponentKind::Earning) {
                    $earning += $amount;
                } else {
                    $deduction += $amount;
                }
            }

            $result[$employeeId] = [
                'rows' => $rows,
                'placements' => array_map(fn (array $placement): array => [
                    'position' => $placement['position'],
                    'organization' => $placement['organization'],
                    'is_primary' => $placement['is_primary'],
                ], $placements[$employeeId] ?? []),
                'positions' => $positionLines,
                'monthly_earning' => $earning,
                'monthly_deduction' => $deduction,
                'per_present_day' => $perDay,
                'configured' => $positionLines !== [] || array_filter($rows, fn (array $row): bool => $row['amount'] !== null) !== [],
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input  id komponen => nominal (teks/angka/null)
     * @return list<string> kode komponen yang berubah
     *
     * @throws PayrollException
     */
    public function save(string $tenantId, string $employeeId, array $input, ?string $membershipId): array
    {
        $employee = Str::isUuid($employeeId)
            ? Employee::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($employeeId)->first()
            : null;

        if (! $employee instanceof Employee) {
            throw PayrollException::make('form', 'Pegawai tidak ditemukan di yayasan ini.');
        }

        $components = [];

        foreach ($this->components($tenantId) as $component) {
            $components[$component->id] = $component;
        }

        $parsed = [];

        foreach ($input as $componentId => $value) {
            $component = $components[(string) $componentId] ?? null;

            if ($component === null) {
                throw PayrollException::make('form', 'Komponen gaji tidak ditemukan. Muat ulang halaman.');
            }

            if (! $component->is_active || ! $component->basis->perEmployee()) {
                continue;
            }

            $parsed[$component->id] = self::parseAmount($value, "amounts.{$component->id}", $component->name);
        }

        return DB::transaction(function () use ($tenantId, $employee, $parsed, $components, $membershipId): array {
            $existing = EmployeePayComponent::query()
                ->withoutGlobalScope('tenant')
                ->where('employee_id', $employee->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('pay_component_id');

            $changed = [];

            foreach ($parsed as $componentId => $amount) {
                $row = $existing->get($componentId);

                if ($amount === null) {
                    if ($row instanceof EmployeePayComponent) {
                        $row->delete();
                        $changed[] = $components[$componentId]->code;
                    }

                    continue;
                }

                if ($row instanceof EmployeePayComponent) {
                    if ($row->amount === $amount) {
                        continue;
                    }

                    $row->amount = $amount;
                    $row->updated_by_membership_id = $membershipId;
                    $row->save();
                } else {
                    $row = new EmployeePayComponent;
                    $row->fill([
                        'tenant_id' => $tenantId,
                        'employee_id' => $employee->id,
                        'pay_component_id' => $componentId,
                        'amount' => $amount,
                        'updated_by_membership_id' => $membershipId,
                    ]);
                    $row->save();
                }

                $changed[] = $components[$componentId]->code;
            }

            if ($changed !== []) {
                sort($changed);

                $this->audit->record(
                    HrAuditAction::EMPLOYEE_PAY_UPDATED,
                    HrAuditAction::SUBJECT_EMPLOYEE,
                    $employee->id,
                    ['komponen' => $changed],
                    tenantId: $tenantId,
                );
            }

            return $changed;
        });
    }

    /**
     * "Rp 2.500.000" → 2500000; kosong → null.
     *
     * @throws PayrollException
     */
    public static function parseAmount(mixed $value, string $field, string $label): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_int($value)) {
            $digits = (string) $value;
        } elseif (is_string($value)) {
            $digits = (string) preg_replace('/^\s*rp\.?\s*/i', '', $value);
            $digits = str_replace(['.', ' '], '', $digits);
        } else {
            $digits = '';
        }

        if (preg_match('/^\d{1,10}$/', $digits) !== 1 || (int) $digits > self::MAX_AMOUNT) {
            throw PayrollException::make($field, "Nominal {$label} harus berupa rupiah bulat 0 s.d. 1.000.000.000 (tanpa koma).");
        }

        return (int) $digits;
    }

    /**
     * Penempatan berjalan per pegawai, utama dulu.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, list<array{position_id: string, organization_id: string, position: string, organization: string, is_primary: bool}>>
     */
    private function placements(string $tenantId, array $employeeIds): array
    {
        $result = [];

        $rows = DB::table('hr_placements as pl')
            ->join('hr_positions as po', 'po.id', '=', 'pl.position_id')
            ->join('organizations as o', 'o.id', '=', 'pl.organization_id')
            ->where('pl.tenant_id', $tenantId)
            ->whereIn('pl.employee_id', $employeeIds)
            ->whereNull('pl.end_date')
            ->orderByDesc('pl.is_primary')
            ->orderBy('pl.start_date')
            ->get(['pl.employee_id', 'pl.position_id', 'pl.organization_id', 'pl.is_primary', 'po.name as position', 'o.name as organization']);

        foreach ($rows as $row) {
            $result[(string) $row->employee_id][] = [
                'position_id' => (string) $row->position_id,
                'organization_id' => (string) $row->organization_id,
                'position' => (string) $row->position,
                'organization' => (string) $row->organization,
                'is_primary' => (bool) $row->is_primary,
            ];
        }

        return $result;
    }

    /**
     * Tarif jabatan: komponen → jabatan → lembaga ('' = semua lembaga) → nominal.
     *
     * @return array<string, array<string, array<string, array{amount: int, scope: 'LEMBAGA'|'SEMUA'}>>>
     */
    private function rates(string $tenantId): array
    {
        $result = [];

        foreach (PayPositionRate::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->get() as $rate) {
            $result[$rate->pay_component_id][$rate->position_id][$rate->organization_id ?? ''] = [
                'amount' => $rate->amount,
                'scope' => $rate->organization_id === null ? 'SEMUA' : 'LEMBAGA',
            ];
        }

        return $result;
    }

    /**
     * @return list<PayComponent>
     */
    private function components(string $tenantId): array
    {
        return array_values(PayComponent::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->orderByRaw("CASE kind WHEN 'PENDAPATAN' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE basis WHEN 'MENURUT_JABATAN' THEN 0 WHEN 'TETAP' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get()
            ->all());
    }
}
