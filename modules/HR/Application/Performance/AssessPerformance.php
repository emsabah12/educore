<?php

namespace Modules\HR\Application\Performance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Support\BusinessClock;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Performance\Exceptions\PerformanceException;
use Modules\HR\Domain\Performance\PerformanceGroup;
use Modules\HR\Domain\Performance\Predicate;

/**
 * Menilai kinerja satu pegawai pada satu periode (PRD-001 §19.2, HR-8b; OD-HR-17, OD-HR-18).
 *
 * - Nilai bilangan bulat 0–100 per komponen kelompoknya (Guru/Staf, boleh diganti penilai); nilai akhir = rata-rata
 *   berbobot, 1 desimal; predikat dari nilai akhir.
 * - Komponen, bobot, dan nilai disalin ke penilaian; menilai ulang menimpa penilaian sebelumnya selama periode belum
 *   dikunci.
 * - Periode harus sudah dimulai dan belum dikunci; pegawai AKTIF dengan penempatan utama; tidak boleh menilai diri
 *   sendiri [ASUMSI, seperti BR-017 cuti].
 * - Catatan kekuatan & saran pengembangan opsional, maks. 500 karakter. Audit tanpa nilai & catatan.
 *
 * Cakupan lembaga penilai dicek pemanggil (controller) dari `$organizationId` = penempatan utama pegawai.
 */
final class AssessPerformance
{
    public const NOTE_MAX = 500;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PerformanceComponents $components,
    ) {}

    /**
     * @param  array<mixed>  $scores  nilai per komponen, berurutan sesuai komponen kelompoknya
     *
     * @throws PerformanceException
     */
    public function assess(
        string $tenantId,
        string $periodId,
        string $employeeId,
        string $organizationId,
        string $group,
        array $scores,
        ?string $strengths,
        ?string $suggestions,
        ?string $assessorMembershipId,
    ): void {
        $groupCase = PerformanceGroup::tryFrom($group) ?? throw PerformanceException::make('group', 'Pilih kelompok penilaian (Guru atau Staf).');
        $components = $this->components->forGroup($tenantId, $groupCase);
        $snapshot = [];
        $total = 0;
        $scores = array_values($scores);

        foreach ($components as $index => $component) {
            $value = $scores[$index] ?? null;
            $value = is_scalar($value) ? trim((string) $value) : '';

            if (preg_match('/^\d{1,3}$/', $value) !== 1 || (int) $value > 100) {
                throw PerformanceException::make("scores.{$index}", "Nilai {$component['name']} harus bilangan bulat 0–100.");
            }

            $snapshot[] = ['name' => $component['name'], 'weight' => $component['weight'], 'score' => (int) $value];
            $total += (int) $value * $component['weight'];
        }

        $final = round($total / 100, 1);
        $predicate = Predicate::fromScore($final);
        $strengths = $this->note($strengths, 'strengths', 'Kekuatan');
        $suggestions = $this->note($suggestions, 'suggestions', 'Saran pengembangan');

        DB::transaction(function () use ($tenantId, $periodId, $employeeId, $organizationId, $groupCase, $snapshot, $final, $predicate, $strengths, $suggestions, $assessorMembershipId): void {
            $period = Str::isUuid($periodId)
                ? DB::table('hr_performance_periods')->where('tenant_id', $tenantId)->where('id', $periodId)->lockForUpdate()->first()
                : null;

            if ($period === null) {
                throw PerformanceException::make('form', 'Periode penilaian tidak ditemukan.');
            }

            if ($period->locked_at !== null) {
                throw PerformanceException::make('form', 'Periode ini sudah dikunci; nilai tidak bisa diubah.');
            }

            if (substr((string) $period->start_date, 0, 10) > BusinessClock::todayString()) {
                throw PerformanceException::make('form', 'Periode ini belum dimulai.');
            }

            $employee = DB::table('hr_employees')->where('tenant_id', $tenantId)->where('id', $employeeId)->first(['membership_id', 'status']);

            if ($employee === null || $employee->status !== 'AKTIF') {
                throw PerformanceException::make('form', 'Hanya pegawai aktif yang bisa dinilai.');
            }

            if ($assessorMembershipId !== null && (string) $employee->membership_id === $assessorMembershipId) {
                throw PerformanceException::make('form', 'Anda tidak bisa menilai kinerja diri sendiri.');
            }

            $existing = DB::table('hr_performance_assessments')->where('period_id', $periodId)->where('employee_id', $employeeId)->lockForUpdate()->value('id');
            $values = [
                'organization_id' => $organizationId,
                'group' => $groupCase->value,
                'scores' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'final_score' => $final,
                'predicate' => $predicate->value,
                'strengths' => $strengths,
                'suggestions' => $suggestions,
                'assessed_by_membership_id' => $assessorMembershipId,
                'updated_at' => now(),
            ];

            if ($existing === null) {
                $id = (string) Str::uuid7();
                DB::table('hr_performance_assessments')->insert($values + [
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'period_id' => $periodId,
                    'employee_id' => $employeeId,
                    'created_at' => now(),
                ]);
            } else {
                $id = (string) $existing;
                DB::table('hr_performance_assessments')->where('id', $id)->update($values);
            }

            $this->audit->record(
                HrAuditAction::PERFORMANCE_ASSESSED,
                HrAuditAction::SUBJECT_PERFORMANCE_ASSESSMENT,
                $id,
                ['pegawai' => $employeeId, 'periode' => $periodId, 'kelompok' => $groupCase->value, 'ubah' => $existing !== null],
                organizationId: $organizationId,
                tenantId: $tenantId,
            );
        });
    }

    /**
     * @throws PerformanceException
     */
    private function note(?string $value, string $field, string $label): ?string
    {
        $value = trim((string) preg_replace('/[ \t]+/u', ' ', str_replace("\r\n", "\n", (string) $value)));

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > self::NOTE_MAX) {
            throw PerformanceException::make($field, $label.' paling banyak '.self::NOTE_MAX.' karakter.');
        }

        return $value;
    }
}
