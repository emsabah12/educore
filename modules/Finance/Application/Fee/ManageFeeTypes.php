<?php

namespace Modules\Finance\Application\Fee;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;
use Modules\Finance\Domain\Fee\FeeKind;
use Modules\Finance\Domain\Fee\FeeType;

/**
 * Jenis biaya siswa (PRD-003 §4, FN-1a): tambah, ubah nama, nonaktifkan, aktifkan kembali.
 * Hak akses (finance.settings.manage tingkat Yayasan) dicek pemanggil.
 *
 * Aturan [ASUMSI disetujui 2026-10-28, mengikuti komponen gaji HR-5a]:
 * - kode huruf besar/angka/"-", 2–30 karakter, unik per yayasan, tidak bisa diubah;
 * - nama wajib, maks. 100;
 * - sifat (Bulanan/Sekali bayar) tidak bisa diubah setelah dibuat (tagihan lama tidak boleh berubah arti);
 * - tidak dihapus: nonaktif = tidak bisa diberi tarif & tidak ditagihkan lagi; data lama tetap.
 */
final class ManageFeeTypes
{
    public const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9-]{1,29}$/';

    public const NAME_MAX_LENGTH = 100;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws FinanceException
     */
    public function create(string $tenantId, string $code, string $name, FeeKind $kind): FeeType
    {
        $code = Str::upper(trim($code));

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw FinanceException::make('code', 'Kode hanya boleh berisi huruf, angka, dan tanda "-", panjang 2–30 karakter.');
        }

        $name = $this->name($name);

        try {
            return DB::transaction(function () use ($tenantId, $code, $name, $kind): FeeType {
                if (FeeType::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
                    throw FinanceException::make('code', "Kode {$code} sudah dipakai jenis biaya lain.");
                }

                $type = new FeeType;
                $type->fill(['tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'kind' => $kind]);
                $type->save();

                $this->audit->record(
                    FinanceAuditAction::FEE_TYPE_CREATED,
                    FinanceAuditAction::SUBJECT_FEE_TYPE,
                    $type->id,
                    ['kode' => $code, 'nama' => $name, 'sifat' => $kind->value],
                    tenantId: $tenantId,
                );

                return $type;
            });
        } catch (UniqueConstraintViolationException) {
            throw FinanceException::make('code', "Kode {$code} sudah dipakai jenis biaya lain.");
        }
    }

    /**
     * @throws FinanceException
     */
    public function rename(string $tenantId, string $typeId, string $name): FeeType
    {
        $name = $this->name($name);

        return DB::transaction(function () use ($tenantId, $typeId, $name): FeeType {
            $type = $this->lock($tenantId, $typeId);

            if ($type->name === $name) {
                return $type;
            }

            $old = $type->name;
            $type->name = $name;
            $type->save();

            $this->audit->record(
                FinanceAuditAction::FEE_TYPE_UPDATED,
                FinanceAuditAction::SUBJECT_FEE_TYPE,
                $type->id,
                ['kode' => $type->code, 'nama' => [$old, $name]],
                tenantId: $tenantId,
            );

            return $type;
        });
    }

    /**
     * @throws FinanceException
     */
    public function setActive(string $tenantId, string $typeId, bool $active): FeeType
    {
        return DB::transaction(function () use ($tenantId, $typeId, $active): FeeType {
            $type = $this->lock($tenantId, $typeId);

            if ($type->is_active === $active) {
                throw FinanceException::make('form', $active ? "{$type->name} sudah aktif." : "{$type->name} sudah nonaktif.");
            }

            $type->is_active = $active;
            $type->save();

            $this->audit->record(
                $active ? FinanceAuditAction::FEE_TYPE_ACTIVATED : FinanceAuditAction::FEE_TYPE_DEACTIVATED,
                FinanceAuditAction::SUBJECT_FEE_TYPE,
                $type->id,
                ['kode' => $type->code, 'nama' => $type->name],
                tenantId: $tenantId,
            );

            return $type;
        });
    }

    /**
     * Jenis biaya milik yayasan ini, dikunci untuk diubah.
     *
     * @throws FinanceException
     */
    public function lock(string $tenantId, string $typeId): FeeType
    {
        $type = Str::isUuid($typeId)
            ? FeeType::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($typeId)->lockForUpdate()->first()
            : null;

        if (! $type instanceof FeeType) {
            throw FinanceException::make('form', 'Jenis biaya tidak ditemukan di yayasan ini.');
        }

        return $type;
    }

    /**
     * @throws FinanceException
     */
    private function name(string $name): string
    {
        $name = (string) preg_replace('/\s+/u', ' ', trim($name));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw FinanceException::make('name', 'Nama jenis biaya wajib diisi, maksimal '.self::NAME_MAX_LENGTH.' karakter.');
        }

        return $name;
    }
}
