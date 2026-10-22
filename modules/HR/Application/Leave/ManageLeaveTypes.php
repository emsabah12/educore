<?php

namespace Modules\HR\Application\Leave;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Leave\Exceptions\LeaveException;
use Modules\HR\Domain\Leave\LeaveType;

/**
 * Jenis cuti/izin (PRD-001 §13.2, HR-3a): tambah, ubah, nonaktifkan, aktifkan kembali.
 *
 * Aturan [ASUMSI disetujui]:
 * - kode huruf besar/angka/"-", 2–30 karakter, unik per yayasan, tidak bisa diubah (sama dengan katalog SDM);
 * - nama wajib, maks. 100;
 * - berjatah → jatah per tahun 1–366 hari; tidak berjatah → tanpa jatah. Sifat berjatah tidak bisa
 *   diubah setelah dibuat (saldo yang sudah berjalan tidak boleh berubah arti);
 * - batas hari per pengajuan opsional, 1–366;
 * - tidak dihapus: nonaktif = tidak bisa dipilih untuk pengajuan baru.
 */
final class ManageLeaveTypes
{
    public const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9-]{1,29}$/';

    public const NAME_MAX_LENGTH = 100;

    public const MAX_DAYS = 366;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws LeaveException
     */
    public function create(string $tenantId, string $code, string $name, bool $hasQuota, ?int $annualQuota, ?int $maxDays): LeaveType
    {
        $code = Str::upper(trim($code));

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw LeaveException::make('code', 'Kode hanya boleh berisi huruf, angka, dan tanda "-", panjang 2–30 karakter.');
        }

        [$name, $annualQuota, $maxDays] = $this->check($name, $hasQuota, $annualQuota, $maxDays);

        try {
            return DB::transaction(function () use ($tenantId, $code, $name, $hasQuota, $annualQuota, $maxDays): LeaveType {
                $exists = LeaveType::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->where('code', $code)->exists();

                if ($exists) {
                    throw LeaveException::make('code', "Kode {$code} sudah dipakai jenis cuti lain.");
                }

                $type = new LeaveType;
                $type->fill([
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'name' => $name,
                    'has_quota' => $hasQuota,
                    'annual_quota' => $annualQuota,
                    'max_days' => $maxDays,
                ]);
                $type->save();

                $this->audit->record(
                    HrAuditAction::LEAVE_TYPE_CREATED,
                    HrAuditAction::SUBJECT_LEAVE_TYPE,
                    $type->id,
                    $this->snapshot($type),
                    tenantId: $tenantId,
                );

                return $type;
            });
        } catch (UniqueConstraintViolationException) {
            throw LeaveException::make('code', "Kode {$code} sudah dipakai jenis cuti lain.");
        }
    }

    /**
     * Ubah nama, jatah per tahun, dan batas hari per pengajuan. Sifat berjatah tetap.
     *
     * @return bool true bila berubah
     *
     * @throws LeaveException
     */
    public function update(string $tenantId, string $typeId, string $name, ?int $annualQuota, ?int $maxDays): bool
    {
        return DB::transaction(function () use ($tenantId, $typeId, $name, $annualQuota, $maxDays): bool {
            $type = $this->lock($tenantId, $typeId);
            [$name, $annualQuota, $maxDays] = $this->check($name, $type->has_quota, $annualQuota, $maxDays);

            $before = $this->snapshot($type);
            $type->name = $name;
            $type->annual_quota = $annualQuota;
            $type->max_days = $maxDays;
            $after = $this->snapshot($type);
            $changes = [];

            foreach ($after as $field => $value) {
                if ($before[$field] !== $value) {
                    $changes[$field] = [$before[$field], $value];
                }
            }

            if ($changes === []) {
                return false;
            }

            $type->save();

            $this->audit->record(
                HrAuditAction::LEAVE_TYPE_UPDATED,
                HrAuditAction::SUBJECT_LEAVE_TYPE,
                $type->id,
                ['kode' => $type->code, ...$changes],
                tenantId: $tenantId,
            );

            return true;
        });
    }

    /**
     * @throws LeaveException
     */
    public function setActive(string $tenantId, string $typeId, bool $active): LeaveType
    {
        return DB::transaction(function () use ($tenantId, $typeId, $active): LeaveType {
            $type = $this->lock($tenantId, $typeId);

            if ($type->is_active === $active) {
                throw LeaveException::make('form', $active ? "{$type->name} sudah aktif." : "{$type->name} sudah nonaktif.");
            }

            $type->is_active = $active;
            $type->save();

            $this->audit->record(
                $active ? HrAuditAction::LEAVE_TYPE_REACTIVATED : HrAuditAction::LEAVE_TYPE_DEACTIVATED,
                HrAuditAction::SUBJECT_LEAVE_TYPE,
                $type->id,
                ['kode' => $type->code, 'nama' => $type->name],
                tenantId: $tenantId,
            );

            return $type;
        });
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * @return array{0: string, 1: int|null, 2: int|null}
     *
     * @throws LeaveException
     */
    private function check(string $name, bool $hasQuota, ?int $annualQuota, ?int $maxDays): array
    {
        $name = (string) preg_replace('/\s+/u', ' ', trim($name));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw LeaveException::make('name', 'Nama jenis cuti wajib diisi, maksimal '.self::NAME_MAX_LENGTH.' karakter.');
        }

        if ($hasQuota && ($annualQuota === null || $annualQuota < 1 || $annualQuota > self::MAX_DAYS)) {
            throw LeaveException::make('annual_quota', 'Isi jatah per tahun 1–'.self::MAX_DAYS.' hari.');
        }

        if ($maxDays !== null && ($maxDays < 1 || $maxDays > self::MAX_DAYS)) {
            throw LeaveException::make('max_days', 'Batas hari per pengajuan 1–'.self::MAX_DAYS.' hari, atau kosongkan.');
        }

        return [$name, $hasQuota ? $annualQuota : null, $maxDays];
    }

    private function lock(string $tenantId, string $typeId): LeaveType
    {
        $type = Str::isUuid($typeId)
            ? LeaveType::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->whereKey($typeId)->lockForUpdate()->first()
            : null;

        if (! $type instanceof LeaveType) {
            throw LeaveException::make('form', 'Jenis cuti tidak ditemukan di yayasan ini.');
        }

        return $type;
    }

    /**
     * @return array{kode: string, nama: string, berjatah: bool, jatah_per_tahun: int|null, maks_hari: int|null}
     */
    private function snapshot(LeaveType $type): array
    {
        return [
            'kode' => $type->code,
            'nama' => $type->name,
            'berjatah' => $type->has_quota,
            'jatah_per_tahun' => $type->annual_quota,
            'maks_hari' => $type->max_days,
        ];
    }
}
