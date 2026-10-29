<?php

namespace Modules\Finance\Application\Discount;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Contracts\AcademicYears;
use Modules\Academic\Contracts\EnrolledStudents;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Application\Fee\ManageFeeRates;
use Modules\Finance\Application\Fee\ManageFeeTypes;
use Modules\Finance\Application\Fee\Rupiah;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;

/**
 * Potongan/beasiswa per siswa (PRD-003 §4, FN-1b). Hak akses (finance.invoices.manage di lembaga
 * pendaftaran) dicek pemanggil.
 *
 * Aturan [ASUMSI disetujui 2026-10-29, rincian FN-1b]:
 * - satu potongan per pendaftaran × jenis biaya × tahun ajaran; simpan ulang = ubah;
 * - PERSEN 1–100 (bulat) atau NOMINAL Rp 1 s.d. Rp 100.000.000 per tagihan;
 * - alasan wajib, maks. 100 karakter (mis. "Yatim", "Anak guru"); tidak dicatat di audit (bisa memuat
 *   keadaan pribadi siswa);
 * - pendaftaran wajib masih aktif di lembaga itu; tahun ajaran dari kalender lembaga; jenis biaya aktif;
 * - hapus boleh; potongan hanya memengaruhi tagihan yang dibuat sesudahnya (FN-2).
 */
final class ManageDiscounts
{
    public const KIND_PERCENT = 'PERSEN';

    public const KIND_AMOUNT = 'NOMINAL';

    public const REASON_MAX_LENGTH = 100;

    public function __construct(
        private readonly ManageFeeTypes $types,
        private readonly AcademicYears $years,
        private readonly EnrolledStudents $students,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return bool true bila potongan baru, false bila potongan yang ada diubah
     *
     * @throws FinanceException
     */
    public function set(string $tenantId, string $organizationId, string $enrollmentId, string $typeId, string $yearId, string $kind, mixed $value, string $reason, ?string $membershipId): bool
    {
        [$percent, $amount] = match ($kind) {
            self::KIND_PERCENT => [$this->percent($value), null],
            self::KIND_AMOUNT => [null, Rupiah::parse($value, 'value', 'nominal potongan', 1, ManageFeeRates::MAX_AMOUNT)],
            default => throw FinanceException::make('kind', 'Pilih jenis potongan: persen atau nominal.'),
        };

        $reason = (string) preg_replace('/\s+/u', ' ', trim($reason));

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw FinanceException::make('reason', 'Alasan potongan wajib diisi, maksimal '.self::REASON_MAX_LENGTH.' karakter.');
        }

        $student = $this->students->describe($tenantId, [$enrollmentId])[$enrollmentId] ?? null;

        if ($student === null || $student['organization_id'] !== $organizationId || ! $student['active']) {
            throw FinanceException::make('enrollment_id', 'Pilih siswa yang masih terdaftar di lembaga ini.');
        }

        $year = $this->year($tenantId, $organizationId, $yearId);

        try {
            return DB::transaction(function () use ($tenantId, $organizationId, $enrollmentId, $typeId, $year, $percent, $amount, $reason, $membershipId): bool {
                $type = $this->types->lock($tenantId, $typeId);

                if (! $type->is_active) {
                    throw FinanceException::make('fee_type_id', "{$type->name} nonaktif; tidak bisa diberi potongan.");
                }

                $existing = DB::table('fn_discounts')
                    ->where('enrollment_id', $enrollmentId)
                    ->where('fee_type_id', $type->id)
                    ->where('academic_year_id', $year['id'])
                    ->lockForUpdate()
                    ->first(['id']);

                $values = [
                    'percent' => $percent,
                    'amount' => $amount,
                    'reason' => $reason,
                    'updated_by_membership_id' => $membershipId,
                    'updated_at' => now(),
                ];

                if ($existing === null) {
                    DB::table('fn_discounts')->insert($values + [
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'organization_id' => $organizationId,
                        'enrollment_id' => $enrollmentId,
                        'fee_type_id' => $type->id,
                        'academic_year_id' => $year['id'],
                        'created_at' => now(),
                    ]);
                } else {
                    DB::table('fn_discounts')->where('id', $existing->id)->update($values);
                }

                $this->audit->record(
                    FinanceAuditAction::DISCOUNT_SET,
                    FinanceAuditAction::SUBJECT_ENROLLMENT,
                    $enrollmentId,
                    ['jenis_biaya' => $type->code, 'tahun_ajaran' => $year['name'], 'potongan' => $percent !== null ? "{$percent}%" : Rupiah::format((int) $amount), 'baru' => $existing === null],
                    organizationId: $organizationId,
                    tenantId: $tenantId,
                );

                return $existing === null;
            });
        } catch (UniqueConstraintViolationException) {
            throw FinanceException::make('form', 'Potongan ini baru saja disimpan pengguna lain. Muat ulang halaman lalu coba lagi.');
        }
    }

    /**
     * Lembaga pemilik potongan (untuk cek hak akses sebelum menghapus), atau null bila tidak ada.
     */
    public function organizationOf(string $tenantId, string $discountId): ?string
    {
        if (! Str::isUuid($discountId)) {
            return null;
        }

        $organizationId = DB::table('fn_discounts')->where('tenant_id', $tenantId)->where('id', $discountId)->value('organization_id');

        return $organizationId === null ? null : (string) $organizationId;
    }

    /**
     * @throws FinanceException
     */
    public function remove(string $tenantId, string $discountId): void
    {
        DB::transaction(function () use ($tenantId, $discountId): void {
            $discount = Str::isUuid($discountId)
                ? DB::table('fn_discounts as d')
                    ->join('fn_fee_types as t', 't.id', '=', 'd.fee_type_id')
                    ->where('d.tenant_id', $tenantId)
                    ->where('d.id', $discountId)
                    ->lockForUpdate()
                    ->first(['d.id', 'd.enrollment_id', 'd.organization_id', 'd.academic_year_id', 't.code'])
                : null;

            if ($discount === null) {
                throw FinanceException::make('form', 'Potongan tidak ditemukan di yayasan ini.');
            }

            DB::table('fn_discounts')->where('id', $discount->id)->delete();

            $this->audit->record(
                FinanceAuditAction::DISCOUNT_REMOVED,
                FinanceAuditAction::SUBJECT_ENROLLMENT,
                (string) $discount->enrollment_id,
                ['jenis_biaya' => (string) $discount->code, 'tahun_ajaran' => $this->yearName($tenantId, (string) $discount->organization_id, (string) $discount->academic_year_id)],
                organizationId: (string) $discount->organization_id,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @throws FinanceException
     */
    private function percent(mixed $value): int
    {
        $text = is_int($value) ? (string) $value : (is_string($value) ? str_replace(['%', ' '], '', trim($value)) : '');

        if (preg_match('/^\d{1,3}$/', $text) !== 1 || (int) $text < 1 || (int) $text > 100) {
            throw FinanceException::make('value', 'Persen potongan harus angka bulat 1–100.');
        }

        return (int) $text;
    }

    /**
     * @return array{id: string, name: string, start_date: string, end_date: string, is_current: bool}
     *
     * @throws FinanceException
     */
    private function year(string $tenantId, string $organizationId, string $yearId): array
    {
        foreach ($this->years->forOrganization($tenantId, $organizationId) as $year) {
            if ($year['id'] === $yearId) {
                return $year;
            }
        }

        throw FinanceException::make('academic_year_id', 'Pilih tahun ajaran dari kalender akademik lembaga ini.');
    }

    private function yearName(string $tenantId, string $organizationId, string $yearId): string
    {
        foreach ($this->years->forOrganization($tenantId, $organizationId) as $year) {
            if ($year['id'] === $yearId) {
                return $year['name'];
            }
        }

        return $yearId;
    }
}
