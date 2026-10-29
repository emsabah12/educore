<?php

namespace Modules\Finance\Application\Fee;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Academic\Contracts\AcademicYears;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Finance\Application\Audit\FinanceAuditAction;
use Modules\Finance\Domain\Fee\Exceptions\FinanceException;

/**
 * Tarif jenis biaya per lembaga × tahun ajaran, opsional per tingkat (PRD-003 §4, FN-1a, OD-FN-04).
 * Hak akses (finance.settings.manage tingkat Yayasan) dicek pemanggil.
 *
 * Aturan [ASUMSI disetujui 2026-10-28]:
 * - lembaga = node LEMBAGA yang aktif di yayasan ini;
 * - tahun ajaran wajib milik kalender akademik yang dipakai lembaga itu (kontrak Academic);
 * - tingkat kosong = semua tingkat; diisi 1–12 = khusus tingkat itu (didahulukan saat menagih);
 * - nominal Rp 1 s.d. Rp 100.000.000; jenis biaya nonaktif tidak bisa diberi tarif baru;
 * - simpan = tambah, atau ubah nominal bila kombinasi itu sudah ada. Tarif tidak mengubah tagihan
 *   yang sudah dibuat (FN-2).
 */
final class ManageFeeRates
{
    public const MIN_AMOUNT = 1;

    public const MAX_AMOUNT = 100_000_000;

    public function __construct(
        private readonly ManageFeeTypes $types,
        private readonly AcademicYears $years,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return bool true bila tarif baru ditambahkan, false bila nominal tarif yang ada diubah
     *
     * @throws FinanceException
     */
    public function set(string $tenantId, string $typeId, string $organizationId, string $yearId, ?int $grade, mixed $amount, ?string $membershipId): bool
    {
        $value = Rupiah::parse($amount, 'amount', 'nominal tarif', self::MIN_AMOUNT, self::MAX_AMOUNT);

        if ($grade !== null && ($grade < 1 || $grade > 12)) {
            throw FinanceException::make('grade', 'Tingkat harus angka 1–12, atau kosong untuk semua tingkat.');
        }

        $organization = $this->organization($tenantId, $organizationId);
        $year = $this->year($tenantId, $organization, $yearId);

        try {
            return DB::transaction(function () use ($tenantId, $typeId, $organization, $year, $grade, $value, $membershipId): bool {
                $type = $this->types->lock($tenantId, $typeId);

                if (! $type->is_active) {
                    throw FinanceException::make('form', "{$type->name} nonaktif. Aktifkan dulu untuk memberi tarif.");
                }

                $existing = DB::table('fn_fee_rates')
                    ->where('fee_type_id', $type->id)
                    ->where('organization_id', $organization->id)
                    ->where('academic_year_id', $year['id'])
                    ->when($grade === null, fn ($query) => $query->whereNull('grade'), fn ($query) => $query->where('grade', $grade))
                    ->lockForUpdate()
                    ->first(['id', 'amount']);

                if ($existing !== null && (int) $existing->amount === $value) {
                    return false;
                }

                if ($existing === null) {
                    DB::table('fn_fee_rates')->insert([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'fee_type_id' => $type->id,
                        'organization_id' => $organization->id,
                        'academic_year_id' => $year['id'],
                        'grade' => $grade,
                        'amount' => $value,
                        'updated_by_membership_id' => $membershipId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('fn_fee_rates')->where('id', $existing->id)->update([
                        'amount' => $value,
                        'updated_by_membership_id' => $membershipId,
                        'updated_at' => now(),
                    ]);
                }

                $this->audit->record(
                    FinanceAuditAction::FEE_RATE_SET,
                    FinanceAuditAction::SUBJECT_FEE_TYPE,
                    $type->id,
                    ['kode' => $type->code, 'lembaga' => $organization->code, 'tahun_ajaran' => $year['name'], 'tingkat' => $grade, 'baru' => $existing === null],
                    organizationId: $organization->id,
                    tenantId: $tenantId,
                );

                return $existing === null;
            });
        } catch (UniqueConstraintViolationException) {
            throw FinanceException::make('form', 'Tarif ini baru saja disimpan pengguna lain. Muat ulang halaman lalu coba lagi.');
        }
    }

    /**
     * @throws FinanceException
     */
    public function remove(string $tenantId, string $rateId): void
    {
        DB::transaction(function () use ($tenantId, $rateId): void {
            $rate = Str::isUuid($rateId)
                ? DB::table('fn_fee_rates as r')
                    ->join('fn_fee_types as t', 't.id', '=', 'r.fee_type_id')
                    ->join('organizations as o', 'o.id', '=', 'r.organization_id')
                    ->where('r.tenant_id', $tenantId)
                    ->where('r.id', $rateId)
                    ->lockForUpdate()
                    ->first(['r.id', 'r.fee_type_id', 'r.organization_id', 'r.academic_year_id', 'r.grade', 't.code', 'o.code as organization'])
                : null;

            if ($rate === null) {
                throw FinanceException::make('form', 'Tarif tidak ditemukan di yayasan ini.');
            }

            DB::table('fn_fee_rates')->where('id', $rate->id)->delete();
            $yearName = null;

            foreach ($this->years->forOrganization($tenantId, (string) $rate->organization_id) as $year) {
                if ($year['id'] === (string) $rate->academic_year_id) {
                    $yearName = $year['name'];
                }
            }

            $this->audit->record(
                FinanceAuditAction::FEE_RATE_REMOVED,
                FinanceAuditAction::SUBJECT_FEE_TYPE,
                (string) $rate->fee_type_id,
                ['kode' => (string) $rate->code, 'lembaga' => (string) $rate->organization, 'tahun_ajaran' => $yearName ?? (string) $rate->academic_year_id, 'tingkat' => $rate->grade === null ? null : (int) $rate->grade],
                organizationId: (string) $rate->organization_id,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @throws FinanceException
     */
    private function organization(string $tenantId, string $organizationId): Organization
    {
        $organization = Str::isUuid($organizationId)
            ? Organization::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('type', OrganizationType::Lembaga->value)
                ->where('status', OrganizationStatus::Active->value)
                ->whereKey($organizationId)
                ->first()
            : null;

        if (! $organization instanceof Organization) {
            throw FinanceException::make('organization_id', 'Pilih lembaga aktif.');
        }

        return $organization;
    }

    /**
     * @return array{id: string, name: string, start_date: string, end_date: string, is_current: bool}
     *
     * @throws FinanceException
     */
    private function year(string $tenantId, Organization $organization, string $yearId): array
    {
        foreach ($this->years->forOrganization($tenantId, $organization->id) as $year) {
            if ($year['id'] === $yearId) {
                return $year;
            }
        }

        throw FinanceException::make('academic_year_id', "Pilih tahun ajaran dari kalender akademik {$organization->name}.");
    }
}
