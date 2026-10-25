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

/**
 * Nominal komponen gaji per pegawai (PRD-001 §16.1, HR-5a). Hak akses (`hr.payroll.manage`) dicek pemanggil.
 *
 * - Nominal rupiah bulat 0 s.d. Rp 1.000.000.000; boleh ditulis "2.500.000" atau "Rp 2.500.000".
 * - Kosong = komponen tidak berlaku untuk pegawai ini (baris dihapus).
 * - Hanya komponen aktif yang bisa diisi; nominal komponen nonaktif tetap tersimpan apa adanya.
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
 * @phpstan-type PaySummary array{
 *     rows: list<PayRow>,
 *     monthly_earning: int,
 *     monthly_deduction: int,
 *     per_present_day: int
 * }
 */
final class EmployeePay
{
    public const MAX_AMOUNT = 1_000_000_000;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Komponen aktif (terisi atau belum) + komponen nonaktif yang masih punya nominal. Pendapatan
     * lebih dulu, lalu potongan; tetap per bulan sebelum per hari hadir.
     *
     * @return PaySummary
     */
    public function forEmployee(string $tenantId, string $employeeId): array
    {
        $amounts = EmployeePayComponent::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->pluck('amount', 'pay_component_id')
            ->all();

        $rows = [];
        $monthlyEarning = 0;
        $monthlyDeduction = 0;
        $perDay = 0;

        foreach ($this->components($tenantId) as $component) {
            $amount = array_key_exists($component->id, $amounts) ? (int) $amounts[$component->id] : null;

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
                $monthlyEarning += $amount;
            } else {
                $monthlyDeduction += $amount;
            }
        }

        return [
            'rows' => $rows,
            'monthly_earning' => $monthlyEarning,
            'monthly_deduction' => $monthlyDeduction,
            'per_present_day' => $perDay,
        ];
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

            if (! $component->is_active) {
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
     * @return list<PayComponent>
     */
    private function components(string $tenantId): array
    {
        return array_values(PayComponent::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->orderByRaw("CASE kind WHEN 'PENDAPATAN' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE basis WHEN 'TETAP' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get()
            ->all());
    }
}
