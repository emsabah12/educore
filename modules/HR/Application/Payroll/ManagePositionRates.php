<?php

namespace Modules\HR\Application\Payroll;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\Organization;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Catalog\Position;
use Modules\HR\Domain\Payroll\Exceptions\PayrollException;
use Modules\HR\Domain\Payroll\PayComponent;
use Modules\HR\Domain\Payroll\PayComponentBasis;
use Modules\HR\Domain\Payroll\PayPositionRate;

/**
 * Tarif komponen gaji "Menurut jabatan" (PRD-001 §16.1, HR-5a-2): simpan (tambah/ubah) dan hapus.
 *
 * Aturan [ASUMSI]:
 * - hanya untuk komponen aktif bercara hitung Menurut jabatan;
 * - jabatan & lembaga harus aktif di yayasan ini; lembaga kosong = berlaku di semua lembaga;
 * - satu tarif per komponen × jabatan × (semua lembaga | lembaga tertentu); menyimpan ulang = mengubah;
 * - tarif boleh dihapus (pegawai berjabatan itu tidak lagi menerima komponen ini, kecuali ada tarif
 *   "semua lembaga"); rekap bulan yang sudah dikunci tidak berubah;
 * - audit tanpa nominal.
 */
final class ManagePositionRates
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return bool true bila tarif baru, false bila mengubah tarif yang ada
     *
     * @throws PayrollException
     */
    public function set(string $tenantId, string $componentId, string $positionId, ?string $organizationId, mixed $amount): bool
    {
        $component = $this->component($tenantId, $componentId);

        $position = Str::isUuid($positionId)
            ? Position::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($positionId)->first()
            : null;

        if (! $position instanceof Position || ! $position->is_active) {
            throw PayrollException::make('position_id', 'Pilih jabatan yang aktif.');
        }

        $organization = null;

        if ($organizationId !== null) {
            $organization = Str::isUuid($organizationId)
                ? Organization::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($organizationId)->first()
                : null;

            if (! $organization instanceof Organization || ! $organization->isActive()) {
                throw PayrollException::make('organization_id', 'Pilih lembaga yang aktif, atau kosongkan untuk semua lembaga.');
            }
        }

        $value = EmployeePay::parseAmount($amount, 'amount', 'tarif');

        if ($value === null) {
            throw PayrollException::make('amount', 'Isi nominal tarif.');
        }

        return DB::transaction(function () use ($tenantId, $component, $position, $organization, $value): bool {
            $rate = PayPositionRate::query()
                ->withoutGlobalScope('tenant')
                ->where('pay_component_id', $component->id)
                ->where('position_id', $position->id)
                ->when($organization === null, fn ($query) => $query->whereNull('organization_id'), fn ($query) => $query->where('organization_id', $organization?->id))
                ->lockForUpdate()
                ->first();

            $created = ! $rate instanceof PayPositionRate;

            if ($rate instanceof PayPositionRate) {
                if ($rate->amount === $value) {
                    return false;
                }

                $rate->amount = $value;
                $rate->save();
            } else {
                $rate = new PayPositionRate;
                $rate->fill([
                    'tenant_id' => $tenantId,
                    'pay_component_id' => $component->id,
                    'position_id' => $position->id,
                    'organization_id' => $organization?->id,
                    'amount' => $value,
                ]);
                $rate->save();
            }

            $this->audit->record(
                HrAuditAction::PAY_RATE_SET,
                HrAuditAction::SUBJECT_PAY_COMPONENT,
                $component->id,
                ['kode' => $component->code, 'jabatan' => $position->code, 'lembaga' => $organization?->code, 'baru' => $created],
                tenantId: $tenantId,
            );

            return $created;
        });
    }

    /**
     * @throws PayrollException
     */
    public function remove(string $tenantId, string $componentId, string $rateId): void
    {
        $component = $this->component($tenantId, $componentId, requireActive: false);

        DB::transaction(function () use ($tenantId, $component, $rateId): void {
            $rate = Str::isUuid($rateId)
                ? PayPositionRate::query()->withoutGlobalScope('tenant')->where('pay_component_id', $component->id)->whereKey($rateId)->lockForUpdate()->first()
                : null;

            if (! $rate instanceof PayPositionRate) {
                throw PayrollException::make('form', 'Tarif tidak ditemukan.');
            }

            $positionCode = (string) Position::query()->withoutGlobalScope('tenant')->whereKey($rate->position_id)->value('code');
            $organizationCode = $rate->organization_id === null ? null : (string) Organization::query()->withoutGlobalScope('tenant')->whereKey($rate->organization_id)->value('code');
            $rate->delete();

            $this->audit->record(
                HrAuditAction::PAY_RATE_REMOVED,
                HrAuditAction::SUBJECT_PAY_COMPONENT,
                $component->id,
                ['kode' => $component->code, 'jabatan' => $positionCode, 'lembaga' => $organizationCode],
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @throws PayrollException
     */
    private function component(string $tenantId, string $componentId, bool $requireActive = true): PayComponent
    {
        $component = Str::isUuid($componentId)
            ? PayComponent::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($componentId)->first()
            : null;

        if (! $component instanceof PayComponent || $component->basis !== PayComponentBasis::PerPosition) {
            throw PayrollException::make('form', 'Komponen gaji menurut jabatan tidak ditemukan.');
        }

        if ($requireActive && ! $component->is_active) {
            throw PayrollException::make('form', "{$component->name} nonaktif. Aktifkan dulu untuk mengubah tarif.");
        }

        return $component;
    }
}
