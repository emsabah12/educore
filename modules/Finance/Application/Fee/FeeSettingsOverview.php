<?php

namespace Modules\Finance\Application\Fee;

use Illuminate\Support\Facades\DB;
use Modules\Academic\Contracts\AcademicYears;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Finance\Domain\Fee\FeeType;

/**
 * Data halaman Pengaturan keuangan (PRD-003 §6, FN-1a): jenis biaya dan tarif satu lembaga × tahun
 * ajaran. Hak akses dicek pemanggil.
 *
 * @phpstan-type TypeRow array{id: string, code: string, name: string, kind: string, kind_label: string, is_active: bool, rates: int}
 * @phpstan-type RateRow array{type_id: string, code: string, name: string, kind_label: string, is_active: bool, all: array{id: string, amount: int}|null, grades: list<array{id: string, grade: int, amount: int}>}
 * @phpstan-type OptionRow array{value: string, label: string}
 */
final class FeeSettingsOverview
{
    public function __construct(
        private readonly AcademicYears $years,
    ) {}

    /**
     * @return list<TypeRow>
     */
    public function types(string $tenantId): array
    {
        $counts = DB::table('fn_fee_rates')->where('tenant_id', $tenantId)
            ->selectRaw('fee_type_id, COUNT(*) AS total')
            ->groupBy('fee_type_id')
            ->pluck('total', 'fee_type_id')
            ->all();

        $rows = [];

        foreach (FeeType::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->orderByDesc('is_active')->orderBy('name')->get() as $type) {
            $rows[] = [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'kind' => $type->kind->value,
                'kind_label' => $type->kind->label(),
                'is_active' => $type->is_active,
                'rates' => (int) ($counts[$type->id] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Lembaga aktif (node LEMBAGA), urut nama.
     *
     * @return list<OptionRow>
     */
    public function organizations(string $tenantId): array
    {
        $options = [];

        foreach (Organization::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('type', OrganizationType::Lembaga->value)
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get() as $organization) {
            $options[] = ['value' => $organization->id, 'label' => $organization->name];
        }

        return $options;
    }

    /**
     * Tahun ajaran kalender lembaga (terbaru dulu), ditandai yang berjalan.
     *
     * @return list<array{value: string, label: string, is_current: bool}>
     */
    public function years(string $tenantId, string $organizationId): array
    {
        $options = [];

        foreach ($this->years->forOrganization($tenantId, $organizationId) as $year) {
            $options[] = [
                'value' => $year['id'],
                'label' => $year['name'].($year['is_current'] ? ' (berjalan)' : ''),
                'is_current' => $year['is_current'],
            ];
        }

        return $options;
    }

    /**
     * Tarif semua jenis biaya di satu lembaga × tahun ajaran. Jenis nonaktif hanya tampil bila sudah punya tarif.
     *
     * @return list<RateRow>
     */
    public function rates(string $tenantId, string $organizationId, string $yearId): array
    {
        $byType = [];

        foreach (DB::table('fn_fee_rates')
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->where('academic_year_id', $yearId)
            ->orderByRaw('grade NULLS FIRST')
            ->get(['id', 'fee_type_id', 'grade', 'amount']) as $rate) {
            $byType[(string) $rate->fee_type_id][] = $rate;
        }

        $rows = [];

        foreach ($this->types($tenantId) as $type) {
            $rates = $byType[$type['id']] ?? [];

            if (! $type['is_active'] && $rates === []) {
                continue;
            }

            $all = null;
            $grades = [];

            foreach ($rates as $rate) {
                if ($rate->grade === null) {
                    $all = ['id' => (string) $rate->id, 'amount' => (int) $rate->amount];
                } else {
                    $grades[] = ['id' => (string) $rate->id, 'grade' => (int) $rate->grade, 'amount' => (int) $rate->amount];
                }
            }

            $rows[] = [
                'type_id' => $type['id'],
                'code' => $type['code'],
                'name' => $type['name'],
                'kind_label' => $type['kind_label'],
                'is_active' => $type['is_active'],
                'all' => $all,
                'grades' => $grades,
            ];
        }

        return $rows;
    }
}
