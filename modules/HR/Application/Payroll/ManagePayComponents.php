<?php

namespace Modules\HR\Application\Payroll;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Payroll\Exceptions\PayrollException;
use Modules\HR\Domain\Payroll\PayComponent;
use Modules\HR\Domain\Payroll\PayComponentBasis;
use Modules\HR\Domain\Payroll\PayComponentKind;

/**
 * Komponen gaji (PRD-001 §16.1, HR-5a): tambah, ubah nama, nonaktifkan, aktifkan kembali.
 *
 * Aturan [ASUMSI disetujui]:
 * - kode huruf besar/angka/"-", 2–30 karakter, unik per yayasan, tidak bisa diubah;
 * - nama wajib, maks. 100;
 * - jenis & cara hitung tidak bisa diubah setelah dibuat (rekap yang sudah dikunci tidak boleh berubah arti);
 * - tidak dihapus: nonaktif = tidak bisa diisi & tidak ikut rekap baru; nominal lama tetap tersimpan.
 */
final class ManagePayComponents
{
    public const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9-]{1,29}$/';

    public const NAME_MAX_LENGTH = 100;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws PayrollException
     */
    public function create(string $tenantId, string $code, string $name, PayComponentKind $kind, PayComponentBasis $basis): PayComponent
    {
        $code = Str::upper(trim($code));

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw PayrollException::make('code', 'Kode hanya boleh berisi huruf, angka, dan tanda "-", panjang 2–30 karakter.');
        }

        $name = $this->name($name);

        try {
            return DB::transaction(function () use ($tenantId, $code, $name, $kind, $basis): PayComponent {
                $exists = PayComponent::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('code', $code)->exists();

                if ($exists) {
                    throw PayrollException::make('code', "Kode {$code} sudah dipakai komponen gaji lain.");
                }

                $component = new PayComponent;
                $component->fill([
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'name' => $name,
                    'kind' => $kind,
                    'basis' => $basis,
                ]);
                $component->save();

                $this->audit->record(
                    HrAuditAction::PAY_COMPONENT_CREATED,
                    HrAuditAction::SUBJECT_PAY_COMPONENT,
                    $component->id,
                    ['kode' => $code, 'nama' => $name, 'jenis' => $kind->value, 'cara_hitung' => $basis->value],
                    tenantId: $tenantId,
                );

                return $component;
            });
        } catch (UniqueConstraintViolationException) {
            throw PayrollException::make('code', "Kode {$code} sudah dipakai komponen gaji lain.");
        }
    }

    /**
     * @throws PayrollException
     */
    public function rename(string $tenantId, string $componentId, string $name): PayComponent
    {
        $name = $this->name($name);

        return DB::transaction(function () use ($tenantId, $componentId, $name): PayComponent {
            $component = $this->lock($tenantId, $componentId);

            if ($component->name === $name) {
                return $component;
            }

            $old = $component->name;
            $component->name = $name;
            $component->save();

            $this->audit->record(
                HrAuditAction::PAY_COMPONENT_UPDATED,
                HrAuditAction::SUBJECT_PAY_COMPONENT,
                $component->id,
                ['kode' => $component->code, 'nama' => [$old, $name]],
                tenantId: $tenantId,
            );

            return $component;
        });
    }

    /**
     * @throws PayrollException
     */
    public function setActive(string $tenantId, string $componentId, bool $active): PayComponent
    {
        return DB::transaction(function () use ($tenantId, $componentId, $active): PayComponent {
            $component = $this->lock($tenantId, $componentId);

            if ($component->is_active === $active) {
                throw PayrollException::make('form', $active ? "{$component->name} sudah aktif." : "{$component->name} sudah nonaktif.");
            }

            $component->is_active = $active;
            $component->save();

            $this->audit->record(
                $active ? HrAuditAction::PAY_COMPONENT_REACTIVATED : HrAuditAction::PAY_COMPONENT_DEACTIVATED,
                HrAuditAction::SUBJECT_PAY_COMPONENT,
                $component->id,
                ['kode' => $component->code, 'nama' => $component->name],
                tenantId: $tenantId,
            );

            return $component;
        });
    }

    /**
     * @throws PayrollException
     */
    private function name(string $name): string
    {
        $name = (string) preg_replace('/\s+/u', ' ', trim($name));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw PayrollException::make('name', 'Nama komponen wajib diisi, maksimal '.self::NAME_MAX_LENGTH.' karakter.');
        }

        return $name;
    }

    /**
     * @throws PayrollException
     */
    private function lock(string $tenantId, string $componentId): PayComponent
    {
        $component = Str::isUuid($componentId)
            ? PayComponent::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($componentId)->lockForUpdate()->first()
            : null;

        if (! $component instanceof PayComponent) {
            throw PayrollException::make('form', 'Komponen gaji tidak ditemukan di yayasan ini.');
        }

        return $component;
    }
}
