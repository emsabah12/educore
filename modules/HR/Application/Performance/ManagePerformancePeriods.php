<?php

namespace Modules\HR\Application\Performance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Performance\Exceptions\PerformanceException;
use stdClass;

/**
 * Periode penilaian kinerja (PRD-001 §19.2, HR-8b): dibuat, diubah, dan dihapus Admin SDM / Admin Yayasan.
 *
 * - Nama 3–100 karakter, tidak kembar (tanpa beda huruf besar/kecil); tanggal selesai ≥ mulai; paling lama 366 hari.
 * - Periode yang sudah dikunci (HR-8b-2) tidak bisa diubah/dihapus; periode yang sudah punya penilaian tidak bisa
 *   dihapus [ASUMSI].
 *
 * Hak akses dicek pemanggil (controller).
 */
final class ManagePerformancePeriods
{
    public const NAME_MIN = 3;

    public const NAME_MAX = 100;

    public const MAX_DAYS = 366;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return string id periode
     *
     * @throws PerformanceException
     */
    public function create(string $tenantId, string $name, string $start, string $end, ?string $membershipId): string
    {
        [$name, $start, $end] = $this->validate($tenantId, $name, $start, $end, null);
        $id = (string) Str::uuid7();

        DB::transaction(function () use ($tenantId, $id, $name, $start, $end, $membershipId): void {
            DB::table('hr_performance_periods')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'name' => $name,
                'start_date' => $start,
                'end_date' => $end,
                'created_by_membership_id' => $membershipId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                HrAuditAction::PERFORMANCE_PERIOD_CREATED,
                HrAuditAction::SUBJECT_PERFORMANCE_PERIOD,
                $id,
                ['nama' => $name, 'mulai' => $start, 'selesai' => $end],
                tenantId: $tenantId,
            );
        });

        return $id;
    }

    /**
     * @throws PerformanceException
     */
    public function update(string $tenantId, string $periodId, string $name, string $start, string $end): void
    {
        DB::transaction(function () use ($tenantId, $periodId, $name, $start, $end): void {
            $period = $this->openPeriod($tenantId, $periodId);
            [$name, $start, $end] = $this->validate($tenantId, $name, $start, $end, $periodId);

            DB::table('hr_performance_periods')->where('id', $periodId)->update([
                'name' => $name,
                'start_date' => $start,
                'end_date' => $end,
                'updated_at' => now(),
            ]);

            $changed = array_keys(array_filter([
                'nama' => $name !== (string) $period->name,
                'mulai' => $start !== substr((string) $period->start_date, 0, 10),
                'selesai' => $end !== substr((string) $period->end_date, 0, 10),
            ]));

            if ($changed !== []) {
                $this->audit->record(
                    HrAuditAction::PERFORMANCE_PERIOD_UPDATED,
                    HrAuditAction::SUBJECT_PERFORMANCE_PERIOD,
                    $periodId,
                    ['diubah' => $changed, 'mulai' => $start, 'selesai' => $end],
                    tenantId: $tenantId,
                );
            }
        });
    }

    /**
     * @throws PerformanceException
     */
    public function delete(string $tenantId, string $periodId): void
    {
        DB::transaction(function () use ($tenantId, $periodId): void {
            $period = $this->openPeriod($tenantId, $periodId);

            if (DB::table('hr_performance_assessments')->where('period_id', $periodId)->exists()) {
                throw PerformanceException::make('form', 'Periode ini sudah punya penilaian, jadi tidak bisa dihapus.');
            }

            DB::table('hr_performance_periods')->where('id', $periodId)->delete();

            $this->audit->record(
                HrAuditAction::PERFORMANCE_PERIOD_DELETED,
                HrAuditAction::SUBJECT_PERFORMANCE_PERIOD,
                $periodId,
                ['nama' => (string) $period->name],
                tenantId: $tenantId,
            );
        });
    }

    /**
     * Periode milik yayasan yang belum dikunci, dikunci barisnya untuk transaksi berjalan.
     *
     * @throws PerformanceException
     */
    private function openPeriod(string $tenantId, string $periodId): stdClass
    {
        $period = Str::isUuid($periodId)
            ? DB::table('hr_performance_periods')->where('tenant_id', $tenantId)->where('id', $periodId)->lockForUpdate()->first()
            : null;

        if ($period === null) {
            throw PerformanceException::make('form', 'Periode penilaian tidak ditemukan.');
        }

        if ($period->locked_at !== null) {
            throw PerformanceException::make('form', 'Periode ini sudah dikunci.');
        }

        return $period;
    }

    /**
     * @return array{string, string, string} nama, mulai, selesai
     *
     * @throws PerformanceException
     */
    private function validate(string $tenantId, string $name, string $start, string $end, ?string $ignoreId): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $length = mb_strlen($name);

        if ($length < self::NAME_MIN || $length > self::NAME_MAX) {
            throw PerformanceException::make('name', 'Nama periode '.self::NAME_MIN.'–'.self::NAME_MAX.' karakter, mis. "Semester Ganjil 2026/2027".');
        }

        $duplicate = DB::table('hr_performance_periods')
            ->where('tenant_id', $tenantId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '<>', $ignoreId))
            ->exists();

        if ($duplicate) {
            throw PerformanceException::make('name', 'Nama periode ini sudah dipakai.');
        }

        $startDate = $this->date($start, 'start_date', 'tanggal mulai');
        $endDate = $this->date($end, 'end_date', 'tanggal selesai');

        if ($endDate < $startDate) {
            throw PerformanceException::make('end_date', 'Tanggal selesai tidak boleh sebelum tanggal mulai.');
        }

        if ($startDate->diffInDays($endDate) + 1 > self::MAX_DAYS) {
            throw PerformanceException::make('end_date', 'Satu periode paling lama '.self::MAX_DAYS.' hari.');
        }

        return [$name, $startDate->toDateString(), $endDate->toDateString()];
    }

    /**
     * @throws PerformanceException
     */
    private function date(string $value, string $field, string $label): CarbonImmutable
    {
        $value = trim($value);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : null;

        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw PerformanceException::make($field, 'Isi '.$label.' yang benar.');
        }

        return $date;
    }
}
